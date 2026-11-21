<?php

/**
 * Maschinenmonitor – Berechnungen
 * (Schichten, Monate, Medien, Rezepte)
 */
class MMRCalc
{
    public const SHIFT_NAMES = ['Früh', 'Spät', 'Nacht'];

    /**
     * Zeitpunkt „Tag + Stunde“ (Stunde darf ≥ 24 sein).
     * Über Datumstexte gerechnet, damit Sommer-/Winterzeit
     * korrekt behandelt wird.
     */
    private static function at(string $day, int $hour): int
    {
        $days = intdiv($hour, 24);
        $date = $days > 0
            ? date('Y-m-d', strtotime($day . ' +' . $days . ' day'))
            : $day;

        return (int)strtotime($date . sprintf(' %02d:00:00', $hour % 24));
    }

    /**
     * Drei Schichten à 8 h, erste Schicht beginnt um $startHour.
     * Standard 6 → Früh 06–14, Spät 14–22, Nacht 22–06.
     * 'day' = Produktionstag (Tag, an dem die Frühschicht beginnt).
     */
    public static function shift(int $ts, int $startHour = 6): array
    {
        $startHour = max(0, min(23, $startHour));
        $today = date('Y-m-d', $ts);
        $best = null;

        foreach ([-1, 0] as $off) {

            $day = $off === 0
                ? $today
                : date('Y-m-d', strtotime($today . ' -1 day'));

            for ($k = 0; $k < 3; $k++) {

                $start = self::at($day, $startHour + 8 * $k);

                if ($start <= $ts && ($best === null || $start > $best['start'])) {
                    $best = ['k' => $k, 'day' => $day, 'start' => $start];
                }
            }
        }

        $k = $best['k'];
        $end = $k < 2
            ? self::at($best['day'], $startHour + 8 * ($k + 1))
            : self::at(date('Y-m-d', strtotime($best['day'] . ' +1 day')), $startHour);

        $name = self::SHIFT_NAMES[$k];

        return [
            'key' => $name . '_' . $best['start'],
            'name' => $name,
            'start' => $best['start'],
            'end' => $end,
            'day' => $best['day']
        ];
    }

    /** Uhrzeiten je Schicht für Tabellenköpfe, z. B. „06–14“. */
    public static function shiftTimes(int $startHour): array
    {
        $out = [];

        foreach (self::SHIFT_NAMES as $k => $name) {
            $a = ($startHour + 8 * $k) % 24;
            $out[$name] = sprintf('%02d–%02d', $a, ($a + 8) % 24);
        }

        return $out;
    }

    /** Mediensummen je Schicht / Monat / Rezept. */
    public static function mediaFields(): array
    {
        return [
            'kaelteTh' => 0.0, // kWh thermisch
            'pumpe' => 0.0,    // kWh elektrisch (Pumpe)
            'hdNm3' => 0.0,    // Nm³ Hochdruck
            'ndNm3' => 0.0     // Nm³ Niederdruck
        ];
    }

    public static function newShift(array $info, int $observed): array
    {
        return $info + [
            'ist' => 0,
            'soll' => 0.0,
            'energie' => 0.0,
            'partial' => $observed > $info['start'],
            'boundaryUncertain' => false,
            'dataGap' => false,
            'counterReset' => false,
            'meterReset' => false,
            'cycleChanges' => 0,
            'rezepte' => []
        ] + self::mediaFields();
    }

    public static function month(int $ts): array
    {
        $start = (int)strtotime(date('Y-m-01 00:00:00', $ts));

        return [
            'key' => date('Y-m', $start),
            'start' => $start,
            'end' => (int)strtotime('+1 month', $start)
        ];
    }

    public static function newMonth(array $info, int $observed): array
    {
        return $info + [
            'stueck' => 0,
            'strom' => 0.0,
            'partial' => $observed > $info['start'],
            'dataGap' => false,
            'counterReset' => false,
            'meterReset' => false,
            'rezepte' => []
        ] + self::mediaFields();
    }

    public static function finish(array $shift): array
    {
        $shift['auslastung'] = $shift['soll'] > 0
            ? 100.0 * $shift['ist'] / $shift['soll']
            : null;

        $shift['complete'] = !(
            $shift['partial']
            || $shift['boundaryUncertain']
            || $shift['dataGap']
            || $shift['counterReset']
            || $shift['meterReset']
        );

        return $shift;
    }

    public static function finishMonth(array $month): array
    {
        $month['complete'] = !(
            $month['partial']
            || $month['dataGap']
            || $month['counterReset']
            || $month['meterReset']
        );

        return $month;
    }

    public static function per(float $value, float $pieces): ?float
    {
        return $pieces > 0 ? $value / $pieces : null;
    }

    /**
     * Energiekennzahlen aus Rohsummen. Parameter (kWh/Nm³,
     * Gesamt-Schalter) wirken dadurch auch rückwirkend.
     */
    public static function evaluate(float $pieces, float $strom, array $r, array $cfg): array
    {
        $kaelteTh = (float)($r['kaelteTh'] ?? 0);
        $pumpe = (float)($r['pumpe'] ?? 0);
        $hd = (float)($r['hdNm3'] ?? 0);
        $nd = (float)($r['ndNm3'] ?? 0);

        $luftEl = $hd * $cfg['hdKwh'] + $nd * $cfg['ndKwh'];

        $gesamt = $strom
            + ($cfg['inclCool'] ? $kaelteTh + $pumpe : 0.0)
            + ($cfg['inclAir'] ? $luftEl : 0.0);

        return [
            'strom' => $strom,
            'kaelteTh' => $kaelteTh,
            'pumpe' => $pumpe,
            'hdNm3' => $hd,
            'ndNm3' => $nd,
            'luftEl' => $luftEl,
            'gesamt' => $gesamt,
            'stromStk' => self::per($strom, $pieces),
            'gesamtStk' => self::per($gesamt, $pieces),
            'kaelteThStk' => self::per($kaelteTh, $pieces),
            'pumpeStk' => self::per($pumpe, $pieces),
            'luftElStk' => self::per($luftEl, $pieces),
            'hdNlStk' => self::per($hd * 1000.0, $pieces),
            'ndNlStk' => self::per($nd * 1000.0, $pieces)
        ];
    }

    /** Summiert Schichten (Tages- und Zeitraumsummen). */
    public static function sumShifts(array $shifts): array
    {
        $sum = ['ist' => 0.0, 'soll' => 0.0, 'energie' => 0.0, 'n' => 0]
            + self::mediaFields();

        foreach ($shifts as $s) {
            $sum['n']++;
            foreach (['ist', 'soll', 'energie', 'kaelteTh', 'pumpe', 'hdNm3', 'ndNm3'] as $k) {
                $sum[$k] += (float)($s[$k] ?? 0);
            }
        }

        return $sum;
    }

    // ------------------------------------------------
    // Rezepte
    // ------------------------------------------------

    /** Leerer Rezept-Datensatz. */
    public static function newRecipe(): array
    {
        return ['sek' => 0.0, 'stk' => 0, 'soll' => 0.0, 'strom' => 0.0]
            + self::mediaFields();
    }

    /** Addiert Werte auf ein Rezept einer Rezeptliste. */
    public static function addRecipe(array &$map, string $name, array $add): void
    {
        if ($name === '') {
            return;
        }

        if (!isset($map[$name]) || !is_array($map[$name])) {
            $map[$name] = self::newRecipe();
        }

        foreach ($add as $k => $v) {
            $map[$name][$k] = ($map[$name][$k] ?? 0) + $v;
        }
    }

    /** Fasst die Rezeptlisten mehrerer Schichten zusammen. */
    public static function mergeRecipes(array $shifts): array
    {
        $out = [];

        foreach ($shifts as $s) {
            foreach ((is_array($s['rezepte'] ?? null) ? $s['rezepte'] : []) as $name => $r) {
                if (is_array($r)) {
                    self::addRecipe($out, (string)$name, $r);
                }
            }
        }

        return $out;
    }

    /** Rezept mit der längsten Laufzeit. */
    public static function recipeMain(array $map): ?string
    {
        $best = null;
        $bestSek = -1.0;

        foreach ($map as $name => $r) {
            $sek = (float)($r['sek'] ?? 0);
            if ($sek > $bestSek) {
                $bestSek = $sek;
                $best = (string)$name;
            }
        }

        return $best;
    }

    /** Rezepte nach Laufzeit absteigend sortiert. */
    public static function recipesSorted(array $map): array
    {
        $list = [];

        foreach ($map as $name => $r) {
            if (is_array($r)) {
                $list[] = ['name' => (string)$name] + $r;
            }
        }

        usort($list, static function ($a, $b) {
            return ((float)$b['sek']) <=> ((float)$a['sek']);
        });

        return $list;
    }
}
