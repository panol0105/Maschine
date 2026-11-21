<?php

/**
 * Maschinenmonitor – Darstellung (FullHD-Kachel)
 *
 * Alle Bereiche mit data-zoom lassen sich per Tippen/Klicken
 * vergrößern. Inhalte mit der Klasse fhd-zo erscheinen nur in
 * der vergrößerten Ansicht, fhd-zh nur in der normalen.
 */
class MMRView
{
    // ------------------------------------------------
    // Formatierung
    // ------------------------------------------------

    public static function num($number, int $decimals = 0): string
    {
        return number_format((float)$number, $decimals, ',', '.');
    }

    public static function opt($number, int $decimals, string $unit = ''): string
    {
        if ($number === null || !is_finite((float)$number)) {
            return '–';
        }

        return self::num($number, $decimals) . ($unit !== '' ? ' ' . $unit : '');
    }

    public static function esc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function monthLabel(int $ts): string
    {
        $names = [
            1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
            'August', 'September', 'Oktober', 'November', 'Dezember'
        ];

        return $names[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    }

    public static function mode(int $mode): array
    {
        $modes = [
            1 => ['Keine', 'muted'],
            2 => ['Einrichten', 'warn'],
            3 => ['Hand', 'warn'],
            4 => ['Auto', 'blue'],
            5 => ['RUN · Produktion', 'good']
        ];

        return $modes[$mode] ?? ['Unbekannt', 'muted'];
    }

    public static function duration(float $sek): string
    {
        $min = (int)round($sek / 60);

        return intdiv($min, 60) . ':' . sprintf('%02d', $min % 60) . ' h';
    }

    public static function utilColor(?float $u): string
    {
        if ($u === null) {
            return '';
        }

        return $u >= 85 ? '#16a34a' : ($u >= 60 ? '#d97706' : '#dc2626');
    }

    // ------------------------------------------------
    // Schrift
    // ------------------------------------------------

    public static function fontFiles(string $dir): array
    {
        $cuts = [
            400 => 'Poppins-Regular',
            500 => 'Poppins-Medium',
            600 => 'Poppins-SemiBold',
            700 => 'Poppins-Bold'
        ];

        $dir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        $found = [];

        foreach ($cuts as $weight => $base) {
            foreach (['ttf', 'woff2', 'woff'] as $ext) {
                if (is_file($dir . $base . '.' . $ext)) {
                    $found[$weight] = $base . '.' . $ext;
                    break;
                }
            }
        }

        return $found;
    }

    public static function fontCss(string $mode, string $url, array $files): string
    {
        if ($mode === 'google') {
            return '<style>@import url(\'https://fonts.googleapis.com/css2'
                . '?family=Poppins:wght@400;500;600;700&display=swap\');</style>';
        }

        if ($mode !== 'lokal' || !$files) {
            return '';
        }

        $fmt = ['ttf' => 'truetype', 'woff2' => 'woff2', 'woff' => 'woff'];
        $css = '';

        foreach ($files as $weight => $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $base = pathinfo($file, PATHINFO_FILENAME);

            $css .= "@font-face{font-family:'Poppins';font-style:normal;"
                . 'font-weight:' . $weight . ';font-display:swap;'
                . "src:local('" . str_replace('-', ' ', $base) . "'),"
                . "local('" . $base . "'),"
                . "url('" . rtrim($url, '/') . '/' . rawurlencode($file) . "')"
                . " format('" . $fmt[$ext] . "');}\n";
        }

        return '<style>' . $css . '</style>';
    }

    // ------------------------------------------------
    // Bausteine
    // ------------------------------------------------

    public static function tile(string $label, string $value, string $unit = '', string $sub = '', string $class = ''): string
    {
        return '<div class="fhd-tile ' . $class . '">'
            . '<div class="fhd-tl">' . self::esc($label) . '</div>'
            . '<div class="fhd-tv">' . self::esc($value)
                . ($unit !== '' && $value !== '–' ? '<small>' . self::esc($unit) . '</small>' : '')
            . '</div>'
            . '<div class="fhd-ts">' . self::esc($sub) . '</div>'
            . '</div>';
    }

    public static function dot(string $class, string $title): string
    {
        return '<span class="fhd-dot ' . $class . '" title="' . self::esc($title) . '">●</span>';
    }

    /** Öffnendes Tag eines vergrößerbaren Bereichs. */
    private static function zoomAttr(string $key, float $factor): string
    {
        return ' data-zoom="' . $key . '" style="--z:' . number_format($factor, 2, '.', '') . '"'
            . ' title="Antippen zum Vergrößern"';
    }

    /** Kurztext „Rezept (+n)“ und Tooltip mit allen Rezepten. */
    private static function recipeShort(array $map): array
    {
        $list = MMRCalc::recipesSorted($map);

        if (!$list) {
            return ['', ''];
        }

        $short = $list[0]['name'] . (count($list) > 1 ? ' +' . (count($list) - 1) : '');
        $title = [];

        foreach ($list as $r) {
            $title[] = $r['name'] . ': ' . self::num($r['stk'] ?? 0) . ' Stk · ' . self::duration((float)$r['sek']);
        }

        return [$short, implode("\n", $title)];
    }

    /**
     * Rezepttabelle (nur in der vergrößerten Ansicht).
     */
    public static function recipeTable(array $map, array $d, string $title, string $current): string
    {
        $cfg = $d['cfg'];
        $list = MMRCalc::recipesSorted($map);
        $air = $d['hdOn'] || $d['ndOn'];

        $html = '<div class="fhd-zo fhd-rzbox"><div class="fhd-h">' . self::esc($title)
            . ' <span>Energie in kWh je Kanister · Druckluft in Nl je Kanister</span></div>';

        if (!$list) {
            return $html . '<div class="fhd-rzempty">Noch keine Rezeptdaten erfasst</div></div>';
        }

        $html .= '<table class="fhd-table fhd-rztable"><thead><tr>'
            . '<th class="l" style="width:28%">Rezept</th><th>Laufzeit</th><th>Kanister</th>'
            . '<th>Ausl.</th><th>Strom</th><th>Gesamt</th>'
            . ($d['coolOn'] ? '<th>Kälte</th>' : '')
            . ($air ? '<th>HD / ND</th>' : '')
            . '</tr></thead><tbody>';

        foreach ($list as $r) {
            $stk = (float)($r['stk'] ?? 0);
            $soll = (float)($r['soll'] ?? 0);
            $u = $soll > 0 ? 100.0 * $stk / $soll : null;
            $ev = MMRCalc::evaluate($stk, (float)($r['strom'] ?? 0), $r, $cfg);
            $isCur = $r['name'] === $current;

            $html .= '<tr' . ($isCur ? ' class="cur"' : '') . '>'
                . '<td class="l">' . ($isCur ? '<strong>' : '') . self::esc($r['name'])
                    . ($isCur ? '</strong> · aktiv' : '') . '</td>'
                . '<td>' . self::duration((float)$r['sek']) . '</td>'
                . '<td>' . self::num($stk) . '</td>'
                . '<td class="em" style="color:' . self::utilColor($u) . '">'
                    . ($u === null ? '–' : self::num($u, 0) . ' %') . '</td>'
                . '<td>' . self::opt($ev['stromStk'], 3) . '</td>'
                . '<td class="em">' . self::opt($ev['gesamtStk'], 3) . '</td>'
                . ($d['coolOn'] ? '<td>' . self::opt($ev['kaelteThStk'], 3) . '</td>' : '')
                . ($air
                    ? '<td>' . ($d['hdOn'] ? self::opt($ev['hdNlStk'], 0) : '–') . ' / '
                        . ($d['ndOn'] ? self::opt($ev['ndNlStk'], 0) : '–') . '</td>'
                    : '')
                . '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    // ------------------------------------------------
    // Verlauf
    // ------------------------------------------------

    public static function chart(array $history, bool $coolOn, bool $recipeOn): string
    {
        $pts = array_values(array_filter($history, static function ($h) {
            return isset($h['ts']);
        }));

        $head = '<section class="fhd-card fhd-chartcard"' . self::zoomAttr('chart', 1.6) . '>'
            . '<div class="fhd-h">Verlauf <span>';

        if (count($pts) < 2) {
            return $head . 'noch keine Daten</span></div><div class="fhd-chart"></div></section>';
        }

        $t0 = (int)$pts[0]['ts'];
        $t1 = (int)end($pts)['ts'];
        $span = max(1, $t1 - $t0);

        $maxKW = 1.0;
        foreach ($pts as $h) {
            $maxKW = max($maxKW, (float)($h['kW'] ?? 0), $coolOn ? (float)($h['coolKW'] ?? 0) : 0.0);
        }
        $step = $maxKW > 50 ? 20 : ($maxKW > 20 ? 10 : 5);
        $maxKW = ceil($maxKW * 1.05 / $step) * $step;

        $utilMax = 120.0;

        $line = static function (string $key, float $max) use ($pts, $t0, $span): string {
            $out = [];
            foreach ($pts as $h) {
                $x = 1000.0 * ((int)$h['ts'] - $t0) / $span;
                $v = (float)($h[$key] ?? 0);
                $v = is_finite($v) ? $v : 0.0;
                $y = 300.0 - 300.0 * min(1.0, max(0.0, $v / $max));
                $out[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
            }
            return implode(' ', $out);
        };

        // Rezeptwechsel und Daten für das Fadenkreuz
        $marks = [];
        $rzNames = [];
        $data = [];
        $prev = null;

        foreach ($pts as $h) {
            $rzIdx = null;

            if ($recipeOn && isset($h['rz'])) {
                $rz = (string)$h['rz'];
                $rzIdx = array_search($rz, $rzNames, true);
                if ($rzIdx === false) {
                    $rzNames[] = $rz;
                    $rzIdx = count($rzNames) - 1;
                }
                if ($prev !== null && $rz !== $prev) {
                    $marks[] = ['ts' => (int)$h['ts'], 'rz' => $rz];
                }
                $prev = $rz;
            }

            $data[] = [
                (int)$h['ts'] - $t0,
                round((float)($h['kW'] ?? 0), 1),
                round((float)($h['coolKW'] ?? 0), 1),
                round((float)($h['util'] ?? 0), 1),
                $rzIdx,
                date('H:i', (int)$h['ts'])
            ];
        }

        $kw = $line('kW', $maxKW);
        $yUtil85 = number_format(300.0 - 300.0 * 85.0 / $utilMax, 1, '.', '');

        $svg = '<svg viewBox="0 0 1000 300" preserveAspectRatio="none">'
            . '<line x1="0" y1="150" x2="1000" y2="150" stroke="#e2e8f0" stroke-width="1" vector-effect="non-scaling-stroke"/>'
            . '<line x1="0" y1="0.5" x2="1000" y2="0.5" stroke="#e2e8f0" stroke-width="1" vector-effect="non-scaling-stroke"/>'
            . '<line x1="0" y1="299.5" x2="1000" y2="299.5" stroke="#cbd5e1" stroke-width="1" vector-effect="non-scaling-stroke"/>'
            . '<line class="fhd-zo" x1="0" y1="' . $yUtil85 . '" x2="1000" y2="' . $yUtil85 . '" stroke="#16a34a" stroke-opacity=".45" stroke-width="1" stroke-dasharray="2 4" vector-effect="non-scaling-stroke"/>';

        foreach ($marks as $m) {
            $x = number_format(1000.0 * ($m['ts'] - $t0) / $span, 1, '.', '');
            $svg .= '<line x1="' . $x . '" y1="0" x2="' . $x . '" y2="300" stroke="#7c3aed" stroke-width="1.5" stroke-dasharray="5 3" vector-effect="non-scaling-stroke"/>';
        }

        $svg .= '<polygon points="0,300 ' . $kw . ' 1000,300" fill="#2563eb" fill-opacity="0.08"/>'
            . '<polyline points="' . $kw . '" fill="none" stroke="#2563eb" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>'
            . ($coolOn
                ? '<polyline points="' . $line('coolKW', $maxKW) . '" fill="none" stroke="#0d9488" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>'
                : '')
            . '<polyline points="' . $line('util', $utilMax) . '" fill="none" stroke="#d97706" stroke-width="1.5" stroke-dasharray="4 3" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>'
            . '</svg>';

        // Beschriftungen (nur vergrößert)
        $over = '<div class="fhd-zo fhd-ylab">'
            . '<span class="l" style="top:0%">' . self::num($maxKW) . ' kW</span>'
            . '<span class="l" style="top:50%">' . self::num($maxKW / 2, $maxKW / 2 == floor($maxKW / 2) ? 0 : 1) . ' kW</span>'
            . '<span class="l" style="top:100%">0 kW</span>'
            . '<span class="r" style="top:0%">120 %</span>'
            . '<span class="r" style="top:' . number_format(100 - 100 * 85 / $utilMax, 2, '.', '') . '%">85 %</span>'
            . '<span class="r" style="top:50%">60 %</span>'
            . '</div>';

        if ($recipeOn && $rzNames) {
            if (isset($pts[0]['rz'])) {
                $over .= '<div class="fhd-zo fhd-rzm" style="left:calc(60 * var(--u));top:calc(24 * var(--u))">'
                    . self::esc((string)$pts[0]['rz']) . '</div>';
            }
            foreach ($marks as $i => $m) {
                $x = 100.0 * ($m['ts'] - $t0) / $span;
                $pos = $x > 70
                    ? 'right:' . number_format(100 - $x, 2, '.', '') . '%;'
                    : 'left:' . number_format($x, 2, '.', '') . '%;';
                $over .= '<div class="fhd-zo fhd-rzm' . ($x > 70 ? ' rt' : '') . '" style="' . $pos . 'top:calc('
                    . (24 + (($i + 1) % 3) * 24) . ' * var(--u))">' . self::esc($m['rz']) . '</div>';
            }
        }

        $over .= '<div class="fhd-xh"></div><div class="fhd-tip"></div>';

        $json = json_encode(
            ['t0' => $t0, 'span' => $span, 'cool' => $coolOn, 'rz' => $rzNames, 'p' => $data],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        // Zeitachse: normal 3 Marken, vergrößert stündlich
        $tick = $span <= 7200 ? 1800 : ($span <= 6 * 3600 ? 3600 : 7200);
        $first = (int)(ceil($t0 / $tick) * $tick);
        $ticks = '';
        for ($t = $first; $t <= $t1; $t += $tick) {
            $ticks .= '<span style="left:' . number_format(100.0 * ($t - $t0) / $span, 2, '.', '') . '%">'
                . date('H:i', $t) . '</span>';
        }

        $mins = (int)round($span / 60);

        return $head . 'letzte ' . ($mins >= 90 ? self::num($mins / 60, 1) . ' h' : $mins . ' min')
            . ' · Skala 0–' . self::num($maxKW) . ' kW</span></div>'
            . '<div class="fhd-chart" data-pts="' . self::esc($json) . '">' . $svg . $over . '</div>'
            . '<div class="fhd-axis fhd-zh"><span>' . date('H:i', $t0) . '</span><span>'
                . date('H:i', $t0 + (int)($span / 2)) . '</span><span>' . date('H:i', $t1) . '</span></div>'
            . '<div class="fhd-zo fhd-axis2">' . $ticks . '</div>'
            . '<div class="fhd-legend">'
                . '<span><i style="background:#2563eb"></i>Leistung kW</span>'
                . ($coolOn ? '<span><i style="background:#0d9488"></i>Kälte kW</span>' : '')
                . '<span><i style="background:#d97706"></i>Auslastung %</span>'
                . ($recipeOn ? '<span><i style="background:#7c3aed"></i>Rezeptwechsel</span>' : '')
                . '<span class="fhd-zo fhd-hint">Antippen / Mauszeiger im Diagramm zeigt Messwerte</span>'
            . '</div>'
            . '</section>';
    }

    // ------------------------------------------------
    // Gesamte Ansicht
    // ------------------------------------------------

    public static function render(array $d): string
    {
        $cfg = $d['cfg'];
        $shift = $d['shift'];
        $se = $d['se'];
        $me = $d['me'];
        $now = $d['now'];
        $util = $d['util'];
        $month = $d['month'];

        $barColor = self::utilColor($util);
        $barCss = number_format(min(100.0, max(0.0, $util)), 2, '.', '');

        $air = $d['hdOn'] || $d['ndOn'];
        $na = 'Nicht konfiguriert';
        $qualityOK = $d['quality'] === 'OK';

        // ========== Kopf ==========

        $html = '<div class="fhd-wrap"><div class="fhd">'
            . '<header class="fhd-head">'
            . '<div class="fhd-title">' . self::esc($d['title']) . '</div>'
            . '<div class="fhd-shiftinfo">'
                . self::esc($shift['name']) . 'schicht · '
                . date('d.m.Y', (int)$shift['start']) . ' · '
                . date('H:i', (int)$shift['start']) . '–'
                . date('H:i', (int)$shift['end']) . ' Uhr · Restzeit '
                . self::num(max(0, ((int)$shift['end'] - $now) / 60)) . ' min'
            . '</div>'
            . '<div class="fhd-right">'
                . '<span class="fhd-stand">Stand ' . date('d.m.Y H:i', $now) . '</span>'
                . ($d['recipeOn']
                    ? '<span class="fhd-pill rz" title="Aktuelles Rezept">Rezept: '
                        . self::esc($d['recipe']) . '</span>'
                    : '')
                . '<span class="fhd-pill ' . ($qualityOK ? 'good' : 'warn')
                    . '" title="' . self::esc($d['quality']) . '">Daten: '
                    . self::esc($qualityOK ? 'OK' : $d['quality']) . '</span>'
                . '<span class="fhd-pill ' . ($d['on'] ? 'good' : 'off') . '">'
                    . ($d['on'] ? '● Maschine AN' : '● Maschine AUS') . '</span>'
                . '<span class="fhd-pill ' . $d['modeInfo'][1] . '">'
                    . self::esc($d['modeInfo'][0]) . '</span>'
            . '</div>'
            . '</header>';

        // ========== Obere Reihe ==========

        $html .= '<div class="fhd-top">';

        // --- A: Auslastung & Produktion ---

        // Prognose und Schichtverlauf (nur vergrößert)
        $restH = max(0.0, ((int)$shift['end'] - $now) / 3600.0);
        $pace = $d['rate'] > 0 ? $d['rate'] * max(0.0, $util) / 100.0 : null;
        $forecast = '<div class="fhd-zo fhd-triple fhd-fc">'
            . '<div><div class="fhd-label">Restzeit Schicht</div><div class="fhd-num">'
                . self::num($restH * 60) . ' min</div></div>'
            . '<div><div class="fhd-label">Prognose Ist bei Schichtende</div><div class="fhd-num">'
                . ($pace === null ? '–' : self::num($d['ist'] + $pace * $restH)) . '</div></div>'
            . '<div><div class="fhd-label">Soll bei Schichtende</div><div class="fhd-num">'
                . ($d['rate'] > 0 ? self::num($d['soll'] + $d['rate'] * $restH) : '–') . '</div></div>'
            . '</div>'
            . self::shiftSpark($d['history'], $shift);

        $recipeRow = '';
        if ($d['recipeOn']) {
            $recipeRow = '<div class="wide"><div class="fhd-label">Rezept · '
                . self::num($d['recipeShiftPieces']) . ' Kanister in dieser Schicht</div>'
                . '<div class="fhd-num rzc" title="' . self::esc($d['recipe']) . '">'
                . self::esc($d['recipe']) . '</div></div>';
        }

        $prodExtra = '<div class="fhd-zo"><div class="fhd-label">Betriebsart</div><div class="fhd-num">'
                . self::esc($d['modeInfo'][0]) . '</div></div>'
            . '<div class="fhd-zo"><div class="fhd-label">Maschine</div><div class="fhd-num">'
                . ($d['on'] ? 'AN' : 'AUS') . '</div></div>'
            . '<div class="fhd-zo"><div class="fhd-label">Strom je Kanister (Schicht)</div><div class="fhd-num">'
                . self::opt($se['stromStk'], 3, 'kWh') . '</div></div>'
            . '<div class="fhd-zo"><div class="fhd-label">Restzeit Schicht</div><div class="fhd-num">'
                . self::num($restH * 60) . ' min</div></div>';

        $html .= '<div class="fhd-col">'
            . '<section class="fhd-card fhd-util"' . self::zoomAttr('util', 2.2) . '>'
                . '<div class="fhd-h">Schichtauslastung <span>Ist / dynamisches Soll</span></div>'
                . '<div class="fhd-big" style="color:' . $barColor . '">' . self::num($util, 1) . ' %</div>'
                . '<div class="fhd-progress"><div class="fhd-fill" style="width:'
                    . $barCss . '%;background:' . $barColor . '"></div></div>'
                . $forecast
                . '<div class="fhd-triple">'
                    . '<div><div class="fhd-label">Ist-Stück</div><div class="fhd-num">'
                        . self::num($d['ist']) . '</div></div>'
                    . '<div><div class="fhd-label">Soll-Stück</div><div class="fhd-num">'
                        . self::num($d['soll'], 1) . '</div></div>'
                    . '<div><div class="fhd-label">Differenz</div><div class="fhd-num">'
                        . self::num($d['diff'], 1) . '</div></div>'
                . '</div>'
            . '</section>'
            . '<section class="fhd-card fhd-prod"' . self::zoomAttr('prod', 2.0) . '>'
                . '<div class="fhd-h">Produktion & Maschine</div>'
                . '<div class="fhd-pair">'
                    . $recipeRow
                    . '<div><div class="fhd-label">Soll-Zykluszeit</div><div class="fhd-num">'
                        . self::num($d['cycleOK'] ? $d['cycle'] : 0) . ' ms</div></div>'
                    . '<div><div class="fhd-label">Soll-Leistung</div><div class="fhd-num">'
                        . self::num($d['rate'], 1) . ' Stk/h</div></div>'
                    . '<div><div class="fhd-label">Leistung aktuell</div><div class="fhd-num">'
                        . ($d['powerOK'] ? self::num($d['power'], 1) : '–') . ' kW</div></div>'
                    . '<div><div class="fhd-label">Energie Schicht</div><div class="fhd-num">'
                        . self::num($d['energy'], 1) . ' kWh</div></div>'
                    . $prodExtra
                . '</div>'
            . '</section>'
            . '</div>';

        // --- B: Aktuelle Schicht je Kanister ---

        $coolLive = $d['coolOK']
            ? 'VL ' . self::num($d['tVL'], 1) . ' / RL ' . self::num($d['tRL'], 1)
                . ' °C · ΔT ' . self::num($d['coolDT'], 1) . ' K · '
                . self::num($d['flowM3h'], 2) . ' m³/h'
                . ($d['pumpOn'] ? ' · Δp ' . self::num($d['coolDP'], 2) . ' bar' : '')
            : ($d['coolOn'] ? 'Messwerte ungültig' : $na);

        $html .= '<section class="fhd-card fhd-group"' . self::zoomAttr('shift', 1.5) . '>'
            . '<div class="fhd-h">Aktuelle Schicht · je Kanister <span>'
                . self::num($d['ist']) . ' Kanister</span></div>'
            . '<div class="fhd-tiles three">'
            . self::tile('Strom je Kanister', self::opt($se['stromStk'], 3), 'kWh',
                'Maschine · ' . self::num($se['strom'], 1) . ' kWh', 'key')
            . self::tile('Energie gesamt je Kanister', self::opt($se['gesamtStk'], 3), 'kWh',
                $d['totalText'], 'key')
            . self::tile('Kälte je Kanister',
                $d['coolOn'] ? self::opt($se['kaelteThStk'], 3) : '–', 'kWh th',
                $d['coolOn'] ? 'Schicht ' . self::num($se['kaelteTh'], 1) . ' kWh th' : $na)
            . self::tile('Kälteleistung aktuell',
                $d['coolOK'] ? self::num($d['coolKW'], 1) : '–', 'kW', $coolLive)
            . self::tile('Blasluft HD je Kanister',
                $d['hdOn'] ? self::opt($se['hdNlStk'], 1) : '–', 'Nl',
                $d['hdOn'] ? 'Aktuell ' . self::num($d['hdLh'], 0) . ' Nl/h · Schicht '
                    . self::num($se['hdNm3'], 1) . ' Nm³' : $na)
            . self::tile('Arbeitsluft ND je Kanister',
                $d['ndOn'] ? self::opt($se['ndNlStk'], 1) : '–', 'Nl',
                $d['ndOn'] ? 'Aktuell ' . self::num($d['ndLh'], 0) . ' Nl/h · Schicht '
                    . self::num($se['ndNm3'], 1) . ' Nm³' : $na)
            . '</div>'
            . ($d['recipeOn']
                ? self::recipeTable($shift['rezepte'] ?? [], $d, 'Rezepte in dieser Schicht', $d['recipe'])
                : '')
            . '</section>';

        // --- C: Laufender Monat ---

        $html .= '<section class="fhd-card fhd-group"' . self::zoomAttr('month', 1.5) . '>'
            . '<div class="fhd-h">' . self::esc(self::monthLabel((int)$month['start']))
                . ' <span>' . self::num($d['monthPieces']) . ' Kanister</span></div>'
            . '<div class="fhd-tiles">'
            . self::tile('Strom je Kanister', self::opt($me['stromStk'], 3), 'kWh',
                self::num($me['strom'], 0) . ' kWh Maschine', 'key')
            . self::tile('Energie gesamt je Kanister', self::opt($me['gesamtStk'], 3), 'kWh',
                self::num($me['gesamt'], 0) . ' kWh gesamt', 'key')
            . self::tile('Kälte je Kanister',
                $d['coolOn'] ? self::opt($me['kaelteThStk'], 3) : '–', 'kWh th',
                $d['coolOn'] ? self::num($me['kaelteTh'], 0) . ' kWh th' : $na)
            . self::tile('Druckluft HD / ND',
                $air
                    ? ($d['hdOn'] ? self::opt($me['hdNlStk'], 0) : '–') . ' / '
                        . ($d['ndOn'] ? self::opt($me['ndNlStk'], 0) : '–')
                    : '–',
                'Nl',
                $air ? '≈ ' . self::opt($me['luftElStk'], 3, 'kWh el') . ' je Kanister' : $na)
            . '</div>'
            . ($d['recipeOn']
                ? self::recipeTable($month['rezepte'] ?? [], $d, 'Rezepte im Monat', $d['recipe'])
                : '')
            . '</section>';

        // --- D: Verlauf ---

        $html .= '<div class="fhd-col">' . self::chart($d['history'], $d['coolOn'], $d['recipeOn']) . '</div>';

        $html .= '</div>'; // fhd-top

        // ========== Untere Reihe ==========

        $html .= '<div class="fhd-bottom">';
        $html .= self::daysTable($d);
        $html .= self::monthTable($d);
        $html .= '</div>';

        // ========== Fuß ==========

        $html .= '<footer class="fhd-foot">'
            . 'Strom = Energiezähler Maschine · Kälte th = V̇ · ρ · cp · (RL − VL), ohne EER (zentrale Kälteanlage)'
            . ($d['pumpOn'] ? ' · Pumpe = V̇ · Δp / η' : '')
            . ' · Druckluft el. = HD · ' . self::num($cfg['hdKwh'], 2)
            . ' + ND · ' . self::num($cfg['ndKwh'], 2) . ' kWh/Nm³'
            . ' · Gesamt = ' . self::esc($d['totalText'])
            . ' · Bereiche antippen zum Vergrößern'
            . '</footer>';

        return $html . '</div></div>';
    }

    // ------------------------------------------------
    // Tabelle: letzte Tage je Schicht
    // ------------------------------------------------

    private static function daysTable(array $d): string
    {
        $cfg = $d['cfg'];
        $shift = $d['shift'];

        $byDay = [];
        $all = $d['archive'];
        $cur = $shift;
        $cur['current'] = true;
        $all[] = $cur;

        foreach ($all as $s) {
            if (!isset($s['start'], $s['name'])) {
                continue;
            }
            $day = (string)($s['day'] ?? date('Y-m-d', (int)$s['start']));
            $byDay[$day][$s['name']] = $s;
        }

        $names = MMRCalc::SHIFT_NAMES;
        $times = MMRCalc::shiftTimes($d['startHour']);
        $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

        $dayRef = (int)strtotime(($shift['day'] ?? date('Y-m-d', (int)$shift['start'])) . ' 12:00:00');
        $nDays = max(1, (int)$d['days']);

        $html = '<section class="fhd-card fhd-tablecard"' . self::zoomAttr('days', $d['recipeOn'] ? 1.35 : 1.6) . '>'
            . '<div class="fhd-h">Letzte ' . $nDays . ' Tage je Schicht'
                . ' <span>Energie in kWh je Kanister · Druckluft in Nl je Kanister · '
                . 'Nachtschicht = Datum des Schichtbeginns'
                . ($d['recipeOn'] ? '<span class="fhd-zo"> · unter Stk: Hauptrezept (+ weitere)</span>' : '')
                . '</span></div>'
            . '<div class="fhd-tablewrap"><table class="fhd-table days' . ($d['recipeOn'] ? ' rzon' : '') . '">'
            . '<colgroup><col class="c-day">'
                . str_repeat('<col class="c-stk"><col class="c-u"><col class="c-s"><col class="c-g">', 3)
                . '<col class="t1"><col class="t2"><col class="t3"><col class="t4"><col class="t5">'
            . '</colgroup>'
            . '<thead><tr><th class="l" rowspan="2" style="text-align:left">Tag</th>';

        foreach ($names as $n) {
            $html .= '<th class="g" colspan="4">' . $n . ' · ' . $times[$n] . '</th>';
        }

        $html .= '<th class="g" colspan="5">Tag gesamt</th></tr><tr>';

        foreach ($names as $n) {
            $html .= '<th class="g">Stk</th><th>Ausl.</th><th>Strom</th><th>Gesamt</th>';
        }

        $html .= '<th class="g">Stk</th><th>Strom</th><th>Kälte</th><th>HD / ND</th><th>Gesamt</th>'
            . '</tr></thead><tbody>';

        $weekByName = ['Früh' => [], 'Spät' => [], 'Nacht' => []];
        $weekAll = [];
        $empty4 = '<td class="g na">–</td><td class="na">–</td><td class="na">–</td><td class="na">–</td>';

        for ($i = 0; $i < $nDays; $i++) {

            $ts = (int)strtotime('-' . $i . ' days', $dayRef);
            $key = date('Y-m-d', $ts);
            $row = $byDay[$key] ?? [];

            $html .= '<tr' . ($i === 0 ? ' class="cur"' : '') . '>'
                . '<td class="l"><strong>' . $wd[(int)date('w', $ts)] . '</strong> '
                    . date('d.m.', $ts) . '</td>';

            foreach ($names as $n) {

                if (!isset($row[$n])) {
                    $html .= $empty4;
                    continue;
                }

                $s = $row[$n];
                $weekByName[$n][] = $s;
                $weekAll[] = $s;

                $sIst = (float)($s['ist'] ?? 0);
                $sSoll = (float)($s['soll'] ?? 0);
                $u = $sSoll > 0 ? 100.0 * $sIst / $sSoll : null;
                $ev = MMRCalc::evaluate($sIst, (float)($s['energie'] ?? 0), $s, $cfg);
                $nowCls = !empty($s['current']) ? ' now' : '';

                [$rzShort, $rzTitle] = $d['recipeOn']
                    ? self::recipeShort(is_array($s['rezepte'] ?? null) ? $s['rezepte'] : [])
                    : ['', ''];

                $title = trim((!empty($s['current']) ? "laufende Schicht\n" : '') . $rzTitle);

                $html .= '<td class="g' . $nowCls . '" title="' . self::esc($title) . '">'
                        . self::num($sIst)
                        . ($rzShort !== '' ? '<span class="fhd-rz fhd-zo">' . self::esc($rzShort) . '</span>' : '')
                        . '</td>'
                    . '<td class="em' . $nowCls . '" style="color:' . self::utilColor($u) . '">'
                        . ($u === null ? '–' : self::num($u, 0) . ' %') . '</td>'
                    . '<td class="' . $nowCls . '">' . self::opt($ev['stromStk'], 3) . '</td>'
                    . '<td class="em' . $nowCls . '">' . self::opt($ev['gesamtStk'], 3) . '</td>';
            }

            $html .= self::dayTotal($row ? array_values($row) : [], $d) . '</tr>';
        }

        $html .= '<tr class="sum"><td class="l">Σ ' . $nDays . ' Tage</td>';

        foreach ($names as $n) {

            $sum = MMRCalc::sumShifts($weekByName[$n]);

            if ($sum['n'] === 0) {
                $html .= $empty4;
                continue;
            }

            $u = $sum['soll'] > 0 ? 100.0 * $sum['ist'] / $sum['soll'] : null;
            $ev = MMRCalc::evaluate($sum['ist'], $sum['energie'], $sum, $cfg);

            $html .= '<td class="g">' . self::num($sum['ist']) . '</td>'
                . '<td style="color:' . self::utilColor($u) . '">'
                    . ($u === null ? '–' : self::num($u, 0) . ' %') . '</td>'
                . '<td>' . self::opt($ev['stromStk'], 3) . '</td>'
                . '<td>' . self::opt($ev['gesamtStk'], 3) . '</td>';
        }

        $html .= self::dayTotal($weekAll, $d) . '</tr>';

        $html .= '</tbody></table></div>';

        if ($d['recipeOn']) {
            $html .= self::recipeTable(
                MMRCalc::mergeRecipes($weekAll),
                $d,
                'Rezepte in den letzten ' . $nDays . ' Tagen',
                $d['recipe']
            );
        }

        return $html . '</section>';
    }

    /**
     * Auslastung im Verlauf der laufenden Schicht (nur vergrößert).
     */
    private static function shiftSpark(array $history, array $shift): string
    {
        $start = (int)$shift['start'];
        $span = max(1, (int)$shift['end'] - $start);
        $pts = [];

        foreach ($history as $h) {
            if (($h['shift'] ?? '') === $shift['key'] && isset($h['ts'])) {
                $x = 1000.0 * ((int)$h['ts'] - $start) / $span;
                $y = 200.0 - 200.0 * min(1.0, max(0.0, (float)($h['util'] ?? 0) / 120.0));
                $pts[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
            }
        }

        $y85 = number_format(200.0 - 200.0 * 85.0 / 120.0, 1, '.', '');
        $y60 = number_format(200.0 - 200.0 * 60.0 / 120.0, 1, '.', '');

        $svg = '<svg viewBox="0 0 1000 200" preserveAspectRatio="none">'
            . '<rect x="0" y="0" width="1000" height="' . $y85 . '" fill="#16a34a" fill-opacity=".05"/>'
            . '<line x1="0" y1="' . $y85 . '" x2="1000" y2="' . $y85 . '" stroke="#16a34a" stroke-opacity=".5" stroke-dasharray="3 4" vector-effect="non-scaling-stroke"/>'
            . '<line x1="0" y1="' . $y60 . '" x2="1000" y2="' . $y60 . '" stroke="#d97706" stroke-opacity=".5" stroke-dasharray="3 4" vector-effect="non-scaling-stroke"/>'
            . '<line x1="0" y1="199.5" x2="1000" y2="199.5" stroke="#cbd5e1" vector-effect="non-scaling-stroke"/>'
            . (count($pts) > 1
                ? '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'
                : '')
            . '</svg>';

        $axis = '';
        for ($i = 0; $i <= 8; $i += 2) {
            $axis .= '<span style="left:' . ($i * 12.5) . '%">' . date('H:i', $start + $i * 3600) . '</span>';
        }

        return '<div class="fhd-zo fhd-spark">'
            . '<div class="fhd-label">Auslastung im Schichtverlauf · Linien bei 85 % und 60 %</div>'
            . '<div class="fhd-sparkbox">' . $svg
                . '<span class="fhd-sl" style="top:0">120 %</span>'
                . '<span class="fhd-sl" style="top:' . number_format(100 - 100 * 85 / 120, 2, '.', '') . '%">85 %</span>'
                . '<span class="fhd-sl" style="top:50%">60 %</span>'
            . '</div>'
            . '<div class="fhd-axis2">' . $axis . '</div>'
            . '</div>';
    }

    private static function dayTotal(array $shifts, array $d): string
    {
        $sum = MMRCalc::sumShifts($shifts);

        if ($sum['n'] === 0) {
            return '<td class="g na">–</td><td class="na">–</td><td class="na">–</td>'
                . '<td class="na">–</td><td class="na">–</td>';
        }

        $ev = MMRCalc::evaluate($sum['ist'], $sum['energie'], $sum, $d['cfg']);

        return '<td class="g">' . self::num($sum['ist']) . '</td>'
            . '<td class="em">' . self::opt($ev['stromStk'], 3) . '</td>'
            . '<td>' . ($d['coolOn'] ? self::opt($ev['kaelteThStk'], 3) : '–') . '</td>'
            . '<td>' . ($d['hdOn'] ? self::opt($ev['hdNlStk'], 0) : '–') . ' / '
                . ($d['ndOn'] ? self::opt($ev['ndNlStk'], 0) : '–') . '</td>'
            . '<td class="em">' . self::opt($ev['gesamtStk'], 3) . '</td>';
    }

    // ------------------------------------------------
    // Tabelle: Monatsübersicht
    // (vergrößert mit Absolutwerten und Hauptrezept)
    // ------------------------------------------------

    private static function monthTable(array $d): string
    {
        $cfg = $d['cfg'];

        $rows = array_slice($d['monthArchive'], -max(0, $d['monthTableRows'] - 1));
        $curM = $d['month'];
        $curM['current'] = true;
        $rows[] = $curM;
        $rows = array_reverse($rows);

        $status = static function (array $m): string {
            return !empty($m['current'])
                ? MMRView::dot('blue', 'laufend')
                : (!empty($m['complete']) ? MMRView::dot('good', 'OK') : MMRView::dot('warn', 'Prüfen'));
        };

        $html = '<section class="fhd-card fhd-tablecard"' . self::zoomAttr('months', 1.5) . '>'
            . '<div class="fhd-h">Monatsübersicht · kWh bzw. Nl je Kanister <span>'
                . self::dot('good', 'OK') . ' OK &nbsp;'
                . self::dot('warn', 'Prüfen') . ' Prüfen &nbsp;'
                . self::dot('blue', 'laufend') . ' laufend</span></div>';

        // Kompakt
        $html .= '<div class="fhd-tablewrap fhd-zh"><table class="fhd-table months">'
            . '<colgroup><col style="width:calc(128 * var(--u))"><col style="width:calc(76 * var(--u))"><col style="width:calc(62 * var(--u))">'
                . '<col style="width:calc(62 * var(--u))"><col style="width:calc(72 * var(--u))"><col style="width:calc(66 * var(--u))">'
                . '<col style="width:calc(26 * var(--u))"></colgroup>'
            . '<thead><tr><th class="l">Monat</th><th>Kanister</th><th>Strom</th>'
            . '<th>Kälte</th><th>HD / ND</th><th>Gesamt</th><th></th></tr></thead><tbody>';

        // Ausführlich (nur vergrößert)
        $full = '<div class="fhd-tablewrap fhd-zo"><table class="fhd-table months full"><thead>'
            . '<tr><th class="l" rowspan="2" style="width:13%">Monat</th><th rowspan="2">Kanister</th>'
            . '<th class="g" colspan="2">Strom</th>'
            . ($d['coolOn'] ? '<th class="g" colspan="2">Kälte th</th>' : '')
            . (($d['hdOn'] || $d['ndOn']) ? '<th class="g" colspan="2">Druckluft HD / ND</th>' : '')
            . '<th class="g" colspan="2">Energie gesamt</th>'
            . ($d['recipeOn'] ? '<th class="g l" rowspan="2" style="width:20%">Hauptrezept</th>' : '')
            . '<th rowspan="2" style="width:4%"></th></tr><tr>'
            . '<th class="g">kWh</th><th>je Stk</th>'
            . ($d['coolOn'] ? '<th class="g">kWh</th><th>je Stk</th>' : '')
            . (($d['hdOn'] || $d['ndOn']) ? '<th class="g">Nm³</th><th>Nl je Stk</th>' : '')
            . '<th class="g">kWh</th><th>je Stk</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $m) {

            $isCur = !empty($m['current']);
            $pcs = (int)($m['stueck'] ?? 0);
            $ev = MMRCalc::evaluate((float)$pcs, (float)($m['strom'] ?? 0), $m, $cfg);
            $tr = '<tr' . ($isCur ? ' class="cur"' : '') . '>';
            $label = self::esc(self::monthLabel((int)$m['start']));
            $airStk = ($d['hdOn'] ? self::opt($ev['hdNlStk'], 0) : '–') . ' / '
                . ($d['ndOn'] ? self::opt($ev['ndNlStk'], 0) : '–');

            $html .= $tr
                . '<td class="l">' . $label . '</td>'
                . '<td>' . self::num($pcs) . '</td>'
                . '<td class="em">' . self::opt($ev['stromStk'], 3) . '</td>'
                . '<td>' . ($d['coolOn'] ? self::opt($ev['kaelteThStk'], 3) : '–') . '</td>'
                . '<td>' . $airStk . '</td>'
                . '<td class="em">' . self::opt($ev['gesamtStk'], 3) . '</td>'
                . '<td style="text-align:center">' . $status($m) . '</td>'
                . '</tr>';

            $rzMain = $d['recipeOn']
                ? MMRCalc::recipeMain(is_array($m['rezepte'] ?? null) ? $m['rezepte'] : [])
                : null;

            $full .= $tr
                . '<td class="l">' . $label . '</td>'
                . '<td>' . self::num($pcs) . '</td>'
                . '<td class="g">' . self::num($ev['strom'], 0) . '</td>'
                . '<td class="em">' . self::opt($ev['stromStk'], 3) . '</td>'
                . ($d['coolOn']
                    ? '<td class="g">' . self::num($ev['kaelteTh'], 0) . '</td><td>' . self::opt($ev['kaelteThStk'], 3) . '</td>'
                    : '')
                . (($d['hdOn'] || $d['ndOn'])
                    ? '<td class="g">' . self::num($ev['hdNm3'], 0) . ' / ' . self::num($ev['ndNm3'], 0) . '</td><td>' . $airStk . '</td>'
                    : '')
                . '<td class="g">' . self::num($ev['gesamt'], 0) . '</td>'
                . '<td class="em">' . self::opt($ev['gesamtStk'], 3) . '</td>'
                . ($d['recipeOn'] ? '<td class="g l">' . self::esc($rzMain ?? '–') . '</td>' : '')
                . '<td style="text-align:center">' . $status($m) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';
        $full .= '</tbody></table></div>';

        return $html . $full . '</section>';
    }
}
