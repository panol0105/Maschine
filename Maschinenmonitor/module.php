<?php

require_once __DIR__ . '/libs/MMRCalc.php';
require_once __DIR__ . '/libs/MMRView.php';

/**
 * MASCHINENMONITOR – Symcon-Modul
 *
 * Schichtauslastung (Ist / dynamisches Soll), Energie je Kanister,
 * Prozesskälte, Druckluft, Monatskennzahlen und Rezepterfassung.
 * Darstellung als Kachel (HTML-SDK) mit vergrößerbaren Bereichen.
 */
class Maschinenmonitor extends IPSModule
{
    /** Pflicht-Eingänge: Eigenschaft => Bezeichnung */
    private const REQUIRED = [
        'CounterID' => 'Stückzähler',
        'CycleID' => 'Soll-Zykluszeit',
        'OnID' => 'Maschinenstatus',
        'ModeID' => 'Betriebsart',
        'PowerID' => 'Leistung',
        'MeterID' => 'Energiezähler'
    ];

    public function Create()
    {
        parent::Create();

        // Allgemein
        $this->RegisterPropertyString('Title', 'Maschinenmonitor');

        // Maschine
        $this->RegisterPropertyInteger('CounterID', 0);
        $this->RegisterPropertyInteger('CycleID', 0);
        $this->RegisterPropertyInteger('OnID', 0);
        $this->RegisterPropertyInteger('ModeID', 0);
        $this->RegisterPropertyInteger('PowerID', 0);
        $this->RegisterPropertyInteger('MeterID', 0);

        // Rezept
        $this->RegisterPropertyInteger('RecipeID', 0);

        // Prozesskälte
        $this->RegisterPropertyInteger('CoolVLTempID', 0);
        $this->RegisterPropertyInteger('CoolRLTempID', 0);
        $this->RegisterPropertyInteger('CoolFlowID', 0);
        $this->RegisterPropertyInteger('CoolVLPresID', 0);
        $this->RegisterPropertyInteger('CoolRLPresID', 0);
        $this->RegisterPropertyFloat('CoolFlowToM3h', 1.0);
        $this->RegisterPropertyFloat('CoolRho', 998.0);
        $this->RegisterPropertyFloat('CoolCp', 4.18);
        $this->RegisterPropertyFloat('PumpEta', 0.6);

        // Druckluft
        $this->RegisterPropertyInteger('AirHDID', 0);
        $this->RegisterPropertyInteger('AirNDID', 0);
        $this->RegisterPropertyFloat('AirHDkWh', 0.25);
        $this->RegisterPropertyFloat('AirNDkWh', 0.12);

        // Gesamtenergie
        $this->RegisterPropertyBoolean('TotalIncludeCooling', false);
        $this->RegisterPropertyBoolean('TotalIncludeAir', false);

        // Schichten & Anzeige
        $this->RegisterPropertyInteger('ShiftStartHour', 6);
        $this->RegisterPropertyString('FontMode', 'lokal');
        $this->RegisterPropertyInteger('Days', 7);
        $this->RegisterPropertyInteger('MonthTableRows', 12);
        $this->RegisterPropertyBoolean('HTMLBox', false);

        // Erweitert
        $this->RegisterPropertyInteger('TimerSeconds', 30);
        $this->RegisterPropertyInteger('MinEventGap', 2);
        $this->RegisterPropertyInteger('HtmlRefreshSeconds', 60);
        $this->RegisterPropertyInteger('MaxArchiveShifts', 180);
        $this->RegisterPropertyInteger('MaxArchiveMonths', 36);
        $this->RegisterPropertyInteger('MaxHistoryPoints', 720);
        $this->RegisterPropertyInteger('MaxSampleGap', 120);

        // Interne Daten
        $this->RegisterAttributeString('State', '{}');
        $this->RegisterAttributeString('Archive', '[]');
        $this->RegisterAttributeString('MonthArchive', '[]');
        $this->RegisterAttributeString('History', '[]');

        $this->RegisterTimer('Update', 0, 'MMR_Update($_IPS[\'TARGET\']);');

        // Kachel-Visualisierung über HTML-SDK
        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($sender, $message);
            }
        }

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->CreateProfiles();
        $this->MaintainVariables();

        [$status, $text] = $this->CheckInputs();

        if ($status !== 102) {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus($status);
            $this->SendDebug('Konfiguration', $text, 0);
            return;
        }

        // Ereignisse nur für seltene Zustandsänderungen
        // (Stückzähler, Leistung, Energie → nur Timer)
        foreach (['CycleID', 'OnID', 'ModeID', 'RecipeID'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        $this->SetStatus(102);
        $this->SetTimerInterval('Update', max(5, $this->ReadPropertyInteger('TimerSeconds')) * 1000);

        // Anzeige mit neuer Konfiguration sofort neu aufbauen
        $this->SetBuffer('Markup', '');
        $this->Update();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }

        // Nur bei echter Wertänderung
        if ($Message === VM_UPDATE && !empty($Data[1])) {
            $this->Process(
                $SenderID === $this->ReadPropertyInteger('RecipeID') ? 'Recipe' : 'Event'
            );
        }
    }

    // ================================================
    // Öffentliche Funktionen
    // ================================================

    /** Messwerte erfassen und Anzeige aktualisieren (Timer / manuell). */
    public function Update(): string
    {
        return $this->Process('Timer');
    }

    /**
     * Übernimmt Zustand, Schicht-/Monatsarchiv und Verlauf aus dem
     * bisherigen Skript (Variablen MMR_State, MMR_Archive, …).
     */
    public function ImportFromScript(int $ScriptID): string
    {
        if ($ScriptID <= 0 || !IPS_ObjectExists($ScriptID)) {
            return 'Bitte das bisherige Maschinenmonitor-Skript auswählen.';
        }

        $map = [
            'State' => ['MMR_State', '{}'],
            'Archive' => ['MMR_Archive', '[]'],
            'MonthArchive' => ['MMR_MonthArchive', '[]'],
            'History' => ['MMR_History', '[]']
        ];

        $data = [];

        foreach ($map as $attr => [$ident, $default]) {
            $vid = @IPS_GetObjectIDByIdent($ident, $ScriptID);

            if ($vid === false || !IPS_VariableExists($vid)) {
                return 'Variable ' . $ident . ' wurde unter dem Skript nicht gefunden.';
            }

            $json = (string)GetValue($vid);
            $decoded = json_decode($json, true);

            if (!is_array($decoded)) {
                return 'Inhalt von ' . $ident . ' ist kein gültiges JSON.';
            }

            $data[$attr] = $decoded;
        }

        $lock = 'MMR_Lock_' . $this->InstanceID;

        if (!IPS_SemaphoreEnter($lock, 10000)) {
            return 'Modul ist gerade beschäftigt, bitte erneut versuchen.';
        }

        try {
            foreach ($data as $attr => $value) {
                $this->WriteAttributeString($attr, json_encode($value, JSON_UNESCAPED_UNICODE));
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }

        $this->SetBuffer('Markup', '');
        $this->Update();

        $timerHint = IPS_ScriptExists($ScriptID) && IPS_GetScriptTimer($ScriptID) > 0
            ? "\n\nHinweis: Das alte Skript läuft noch (Timer aktiv). Bitte dessen Timer "
                . 'und Ereignisse deaktivieren, damit nicht doppelt gerechnet wird.'
            : '';

        return 'Übernommen: ' . count($data['Archive']) . ' Schichten, '
            . count($data['MonthArchive']) . ' Monate, '
            . count($data['History']) . ' Verlaufspunkte.' . $timerHint;
    }

    /** Löscht alle gesammelten Daten (Schichten, Monate, Verlauf). */
    public function ResetData(): string
    {
        $lock = 'MMR_Lock_' . $this->InstanceID;

        if (!IPS_SemaphoreEnter($lock, 10000)) {
            return 'Modul ist gerade beschäftigt, bitte erneut versuchen.';
        }

        try {
            $this->WriteAttributeString('State', '{}');
            $this->WriteAttributeString('Archive', '[]');
            $this->WriteAttributeString('MonthArchive', '[]');
            $this->WriteAttributeString('History', '[]');
        } finally {
            IPS_SemaphoreLeave($lock);
        }

        $this->SetBuffer('Markup', '');
        $this->Update();

        return 'Alle Daten wurden zurückgesetzt.';
    }

    /** Kachel-Visualisierung: vollständige Seite. */
    public function GetVisualizationTile()
    {
        if ($this->GetBuffer('Markup') === '') {
            $this->Update();
        }

        $markup = $this->GetBuffer('Markup');

        if ($markup === '') {
            $markup = '<div style="font-family:Arial,sans-serif;color:#54575A;padding:20px">'
                . 'Maschinenmonitor: keine Daten – bitte Konfiguration der Instanz prüfen.</div>';
        }

        return $this->BuildPage($markup);
    }

    // ================================================
    // Konfiguration
    // ================================================

    private function CheckInputs(): array
    {
        $inputs = [];

        foreach (self::REQUIRED as $prop => $label) {
            $inputs[$label] = $this->ReadPropertyInteger($prop);
        }

        $f = $this->Features();

        if ($f['coolOn']) {
            $inputs['Kühlwasser Vorlauftemperatur'] = $this->ReadPropertyInteger('CoolVLTempID');
            $inputs['Kühlwasser Rücklauftemperatur'] = $this->ReadPropertyInteger('CoolRLTempID');
            $inputs['Kühlwasser Volumenstrom'] = $this->ReadPropertyInteger('CoolFlowID');
        }
        if ($f['pumpOn']) {
            $inputs['Kühlwasser Druck Vorlauf'] = $this->ReadPropertyInteger('CoolVLPresID');
            $inputs['Kühlwasser Druck Rücklauf'] = $this->ReadPropertyInteger('CoolRLPresID');
        }
        if ($f['hdOn']) {
            $inputs['Blasluft Hochdruck'] = $this->ReadPropertyInteger('AirHDID');
        }
        if ($f['ndOn']) {
            $inputs['Arbeitsluft Niederdruck'] = $this->ReadPropertyInteger('AirNDID');
        }

        foreach ($inputs as $label => $id) {
            if ($id <= 0 || !IPS_VariableExists($id)) {
                return [201, 'Variable fehlt: ' . $label . ' (' . $id . ')'];
            }
            if (!in_array(IPS_GetVariable($id)['VariableType'], [0, 1, 2], true)) {
                return [202, 'Ungültiger Datentyp: ' . $label];
            }
        }

        $rid = $this->ReadPropertyInteger('RecipeID');
        if ($rid > 0 && !IPS_VariableExists($rid)) {
            return [203, 'Rezeptvariable existiert nicht (' . $rid . ')'];
        }

        return [102, 'OK'];
    }

    private function Features(): array
    {
        $coolOn = $this->ReadPropertyInteger('CoolVLTempID') > 0
            && $this->ReadPropertyInteger('CoolRLTempID') > 0
            && $this->ReadPropertyInteger('CoolFlowID') > 0;

        $rid = $this->ReadPropertyInteger('RecipeID');

        return [
            'coolOn' => $coolOn,
            'pumpOn' => $coolOn
                && $this->ReadPropertyInteger('CoolVLPresID') > 0
                && $this->ReadPropertyInteger('CoolRLPresID') > 0,
            'hdOn' => $this->ReadPropertyInteger('AirHDID') > 0,
            'ndOn' => $this->ReadPropertyInteger('AirNDID') > 0,
            'recipeOn' => $rid > 0 && IPS_VariableExists($rid)
        ];
    }

    private function CreateProfiles(): void
    {
        $profiles = [
            ['MMR.Pieces', 1, ' Stk', 0],
            ['MMR.Pieces1', 2, ' Stk', 1],
            ['MMR.Percent', 2, ' %', 1],
            ['MMR.ms', 2, ' ms', 0],
            ['MMR.PcsH', 2, ' Stk/h', 1],
            ['MMR.kW', 2, ' kW', 2],
            ['MMR.kWh', 2, ' kWh', 2],
            ['MMR.kWh4', 2, ' kWh', 4],
            ['MMR.K', 2, ' K', 1],
            ['MMR.Nm3', 2, ' Nm³', 2],
            ['MMR.Nl', 2, ' Nl', 1]
        ];

        foreach ($profiles as [$name, $type, $suffix, $digits]) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $type);
                IPS_SetVariableProfileText($name, '', $suffix);
                if ($type === 2) {
                    IPS_SetVariableProfileDigits($name, $digits);
                }
            }
        }

        if (!IPS_VariableProfileExists('MMR.OnOff')) {
            IPS_CreateVariableProfile('MMR.OnOff', 0);
            IPS_SetVariableProfileAssociation('MMR.OnOff', 0, 'AUS', '', 0x808080);
            IPS_SetVariableProfileAssociation('MMR.OnOff', 1, 'AN', '', 0x16A34A);
        }
    }

    /**
     * Legt Ausgabevariablen an. Variablen nicht konfigurierter
     * Medien werden nicht angelegt, bestehende aber nie gelöscht
     * (Archivdaten bleiben erhalten).
     */
    private function MaintainVariables(): void
    {
        $f = $this->Features();
        $air = $f['hdOn'] || $f['ndOn'];

        $vars = [
            ['Shift', 'Aktuelle Schicht', 3, '', true],
            ['Recipe', 'Aktuelles Rezept', 3, '', $f['recipeOn']],
            ['Ist', 'Ist-Stück Schicht', 1, 'MMR.Pieces', true],
            ['Soll', 'Soll-Stück Schicht', 2, 'MMR.Pieces1', true],
            ['Util', 'Schichtauslastung', 2, 'MMR.Percent', true],
            ['Diff', 'Stückabweichung', 2, 'MMR.Pieces1', true],
            ['Cycle', 'Soll-Zykluszeit', 2, 'MMR.ms', true],
            ['Rate', 'Soll-Leistung', 2, 'MMR.PcsH', true],
            ['On', 'Maschine AN', 0, 'MMR.OnOff', true],
            ['Mode', 'Betriebsart Code', 1, '', true],
            ['ModeText', 'Betriebsart', 3, '', true],
            ['Power', 'Leistung aktuell', 2, 'MMR.kW', true],
            ['Energy', 'Energie Schicht', 2, 'MMR.kWh', true],
            ['Specific', 'Energie pro Stück', 2, 'MMR.kWh4', true],
            ['RecipePieces', 'Kanister im Rezept (Schicht)', 1, 'MMR.Pieces', $f['recipeOn']],
            ['CoolPower', 'Kälteleistung aktuell', 2, 'MMR.kW', $f['coolOn']],
            ['CoolDT', 'Kühlwasser Spreizung', 2, 'MMR.K', $f['coolOn']],
            ['CoolShift', 'Kälte Schicht (th)', 2, 'MMR.kWh', $f['coolOn']],
            ['CoolPerPc', 'Kälte je Kanister (th)', 2, 'MMR.kWh4', $f['coolOn']],
            ['PumpPerPc', 'Pumpenanteil je Kanister (el)', 2, 'MMR.kWh4', $f['pumpOn']],
            ['HDShift', 'Blasluft HD Schicht', 2, 'MMR.Nm3', $f['hdOn']],
            ['HDPerPc', 'Blasluft HD je Kanister', 2, 'MMR.Nl', $f['hdOn']],
            ['NDShift', 'Arbeitsluft ND Schicht', 2, 'MMR.Nm3', $f['ndOn']],
            ['NDPerPc', 'Arbeitsluft ND je Kanister', 2, 'MMR.Nl', $f['ndOn']],
            ['AirElPerPc', 'Druckluft je Kanister (el)', 2, 'MMR.kWh4', $air],
            ['TotalPerPc', 'Energie gesamt je Kanister Schicht', 2, 'MMR.kWh4', true],
            ['MonthPieces', 'Kanister Monat', 1, 'MMR.Pieces', true],
            ['MonthPower', 'Strom Monat', 2, 'MMR.kWh', true],
            ['MonthPowerPerPc', 'Monat: Strom je Kanister', 2, 'MMR.kWh4', true],
            ['MonthTotal', 'Energie gesamt Monat', 2, 'MMR.kWh', true],
            ['MonthTotalPerPc', 'Monat: Energie gesamt je Kanister', 2, 'MMR.kWh4', true],
            ['Quality', 'Datenqualität', 3, '', true],
            ['HTML', 'Übersicht (HTML-Box)', 3, '~HTMLBox', $this->ReadPropertyBoolean('HTMLBox')]
        ];

        $pos = 1;

        foreach ($vars as [$ident, $name, $type, $profile, $keep]) {
            if ($keep) {
                $this->MaintainVariable($ident, $name, $type, $profile, $pos, true);
            }
            $pos++;
        }
    }

    /** Setzt eine eigene Variable nur bei Änderung (und nur, wenn vorhanden). */
    private function Out(string $ident, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($id === false || !IPS_VariableExists($id)) {
            return;
        }

        if (GetValue($id) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function JsonAttr(string $name): array
    {
        $decoded = json_decode($this->ReadAttributeString($name), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function BuildPage(string $markup): string
    {
        $css = (string)@file_get_contents(__DIR__ . '/assets/visu.css');
        $js = (string)@file_get_contents(__DIR__ . '/assets/visu.js');

        return '<style>' . $css . '</style>'
            . '<div id="mmr-root">' . $markup . '</div>'
            . '<script>' . $js . '</script>';
    }

    // ================================================
    // Messung und Berechnung
    // ================================================

    private function Process(string $sender): string
    {
        [$status, $text] = $this->CheckInputs();

        if ($status !== 102) {
            if ($this->GetStatus() !== $status) {
                $this->SetStatus($status);
            }
            return $text;
        }

        $lock = 'MMR_Lock_' . $this->InstanceID;

        if (!IPS_SemaphoreEnter($lock, 10000)) {
            return '';
        }

        try {
            return $this->Measure($sender);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function Measure(string $sender): string
    {
        $f = $this->Features();
        $coolOn = $f['coolOn'];
        $pumpOn = $f['pumpOn'];
        $hdOn = $f['hdOn'];
        $ndOn = $f['ndOn'];
        $recipeOn = $f['recipeOn'];

        $startHour = $this->ReadPropertyInteger('ShiftStartHour');
        $maxSampleGap = $this->ReadPropertyInteger('MaxSampleGap');
        $pumpEta = $this->ReadPropertyFloat('PumpEta');

        $cfg = [
            'hdKwh' => $this->ReadPropertyFloat('AirHDkWh'),
            'ndKwh' => $this->ReadPropertyFloat('AirNDkWh'),
            'inclCool' => $this->ReadPropertyBoolean('TotalIncludeCooling'),
            'inclAir' => $this->ReadPropertyBoolean('TotalIncludeAir')
        ];

        // ----------------------------------------
        // Messwerte einlesen
        // ----------------------------------------

        $now = time();

        $counter = (int)GetValue($this->ReadPropertyInteger('CounterID'));
        $cycle = (float)GetValue($this->ReadPropertyInteger('CycleID'));
        $on = (bool)GetValue($this->ReadPropertyInteger('OnID'));
        $mode = (int)GetValue($this->ReadPropertyInteger('ModeID'));
        $power = (float)GetValue($this->ReadPropertyInteger('PowerID'));
        $meter = (float)GetValue($this->ReadPropertyInteger('MeterID'));

        $cycleOK = is_finite($cycle) && $cycle > 0;
        $meterOK = is_finite($meter) && $meter >= 0;
        $powerOK = is_finite($power);

        $modeInfo = MMRView::mode($mode);

        // Rezept (String oder Integer/Float mit Profil → formatierter Text)
        $recipe = '';
        if ($recipeOn) {
            $recipe = trim((string)GetValueFormatted($this->ReadPropertyInteger('RecipeID')));
            if ($recipe === '') {
                $recipe = '–';
            }
        }

        // Prozesskälte
        $tVL = $coolOn ? (float)GetValue($this->ReadPropertyInteger('CoolVLTempID')) : NAN;
        $tRL = $coolOn ? (float)GetValue($this->ReadPropertyInteger('CoolRLTempID')) : NAN;

        $flowM3h = $coolOn
            ? (float)GetValue($this->ReadPropertyInteger('CoolFlowID')) * $this->ReadPropertyFloat('CoolFlowToM3h')
            : 0.0;

        $coolOK = $coolOn && is_finite($tVL) && is_finite($tRL) && is_finite($flowM3h);
        $coolDT = $coolOK ? $tRL - $tVL : 0.0;

        // Q̇ [kW] = V̇ [m³/h] · ρ · cp · ΔT / 3600
        $coolKW = $coolOK
            ? max(0.0, $flowM3h) * $this->ReadPropertyFloat('CoolRho')
                * $this->ReadPropertyFloat('CoolCp') * max(0.0, $coolDT) / 3600.0
            : 0.0;

        // P [kW] = V̇ [m³/s] · Δp [Pa] / 1000 / η
        $pVL = $pumpOn ? (float)GetValue($this->ReadPropertyInteger('CoolVLPresID')) : NAN;
        $pRL = $pumpOn ? (float)GetValue($this->ReadPropertyInteger('CoolRLPresID')) : NAN;

        $pumpOK = $pumpOn && $coolOK && is_finite($pVL) && is_finite($pRL) && $pumpEta > 0;
        $coolDP = $pumpOK ? $pVL - $pRL : 0.0;

        $pumpKW = $pumpOK
            ? max(0.0, $flowM3h) / 3600.0 * max(0.0, $coolDP) * 1e5 / 1000.0 / $pumpEta
            : 0.0;

        // Druckluft (Nl/h)
        $hdRaw = $hdOn ? (float)GetValue($this->ReadPropertyInteger('AirHDID')) : NAN;
        $ndRaw = $ndOn ? (float)GetValue($this->ReadPropertyInteger('AirNDID')) : NAN;

        $hdOK = $hdOn && is_finite($hdRaw);
        $ndOK = $ndOn && is_finite($ndRaw);

        $hdLh = $hdOK ? max(0.0, $hdRaw) : 0.0;
        $ndLh = $ndOK ? max(0.0, $ndRaw) : 0.0;

        $rates = ['cool' => $coolKW, 'pump' => $pumpKW, 'hd' => $hdLh, 'nd' => $ndLh];

        // ----------------------------------------
        // Gespeicherte Daten
        // ----------------------------------------

        $archive = $this->JsonAttr('Archive');
        $monthArchive = $this->JsonAttr('MonthArchive');
        $history = $this->JsonAttr('History');
        $state = $this->JsonAttr('State');

        $info = MMRCalc::shift($now, $startHour);

        $firstRun = !isset($state['shift'], $state['lastTime'], $state['lastCounter'], $state['lastMeter']);

        // Mehrere Auslöser kurz hintereinander → nur einmal rechnen.
        // Timer, manueller Start und Rezeptwechsel laufen immer.
        if (
            !$firstRun
            && $sender === 'Event'
            && ($now - (int)$state['lastTime']) < $this->ReadPropertyInteger('MinEventGap')
        ) {
            return '';
        }

        if ($firstRun) {

            $state = [
                'lastTime' => $now,
                'lastCounter' => $counter,
                'lastMeter' => $meter,
                'cycleMs' => $cycleOK ? $cycle : 0.0,
                'lastRates' => $rates,
                'lastRecipe' => $recipe,
                'shift' => MMRCalc::newShift($info, $now),
                'month' => MMRCalc::newMonth(MMRCalc::month($now), $now)
            ];

        } else {

            // Migration (Daten aus dem Skript bzw. älterer Version)
            $state['shift'] += MMRCalc::mediaFields() + ['rezepte' => []];

            if (!isset($state['month']['key'])) {
                $state['month'] = MMRCalc::newMonth(MMRCalc::month($now), $now);
            }

            $state['month'] += MMRCalc::mediaFields() + ['rezepte' => []];

            if (!is_array($state['shift']['rezepte'])) {
                $state['shift']['rezepte'] = [];
            }
            if (!is_array($state['month']['rezepte'])) {
                $state['month']['rezepte'] = [];
            }

            // ------------------------------------
            // Zählerdifferenzen
            // ------------------------------------

            $lastTime = (int)$state['lastTime'];

            if ($now < $lastTime) {
                $this->LogMessage('Systemzeit liegt vor dem letzten Lauf', KL_WARNING);
                return 'Systemzeit liegt vor dem letzten Lauf';
            }

            $secondsTotal = $now - $lastTime;
            $oldCycle = (float)($state['cycleMs'] ?? 0);

            $counterReset = $counter < (int)$state['lastCounter'];
            $meterReset = $meterOK && $meter < (float)$state['lastMeter'];

            $deltaPieces = $counterReset ? 0 : max(0, $counter - (int)$state['lastCounter']);

            $deltaEnergy = ($meterOK && !$meterReset)
                ? max(0.0, $meter - (float)$state['lastMeter'])
                : 0.0;

            $gap = $secondsTotal > $maxSampleGap;

            // Rezept, das seit dem letzten Lauf aktiv war
            $rzPrev = $recipeOn ? (string)($state['lastRecipe'] ?? $recipe) : '';
            if ($recipeOn && $rzPrev === '') {
                $rzPrev = $recipe;
            }

            // ------------------------------------
            // Medien integrieren (Trapez)
            // ------------------------------------

            $prev = $state['lastRates'] ?? $rates;
            $hours = $secondsTotal / 3600.0;

            $avg = static function (string $k) use ($prev, $rates): float {
                return ((float)($prev[$k] ?? $rates[$k]) + (float)$rates[$k]) / 2.0;
            };

            $deltaMedia = $gap
                ? MMRCalc::mediaFields()
                : [
                    'kaelteTh' => $avg('cool') * $hours,
                    'pumpe' => $avg('pump') * $hours,
                    'hdNm3' => $avg('hd') / 1000.0 * $hours,
                    'ndNm3' => $avg('nd') / 1000.0 * $hours
                ];

            // ------------------------------------
            // Schicht- und Monatsberechnung
            // ------------------------------------

            $cursor = $lastTime;
            $crossed = false;

            while ($cursor < $now) {

                $segment = MMRCalc::shift($cursor, $startHour);
                $monthSeg = MMRCalc::month($cursor);

                $segmentEnd = min($now, $segment['end'], $monthSeg['end']);

                if ($segmentEnd <= $cursor) {
                    break;
                }

                $dt = $segmentEnd - $cursor;
                $share = $secondsTotal > 0 ? $dt / $secondsTotal : 0.0;

                // Dynamisches Soll
                $sollAdd = 0.0;
                if ($oldCycle > 0) {
                    $sollAdd = $dt * 1000.0 / $oldCycle;
                    $state['shift']['soll'] += $sollAdd;
                } else {
                    $state['shift']['dataGap'] = true;
                }

                // Energie und Medien anteilig
                $state['shift']['energie'] += $deltaEnergy * $share;
                $state['month']['strom'] += $deltaEnergy * $share;

                $mediaShare = [];
                foreach ($deltaMedia as $k => $v) {
                    $state['shift'][$k] += $v * $share;
                    $state['month'][$k] += $v * $share;
                    $mediaShare[$k] = $v * $share;
                }

                // Rezeptkennzahlen
                if ($recipeOn) {
                    $add = [
                        'sek' => (float)$dt,
                        'soll' => $sollAdd,
                        'strom' => $deltaEnergy * $share
                    ] + $mediaShare;

                    MMRCalc::addRecipe($state['shift']['rezepte'], $rzPrev, $add);
                    MMRCalc::addRecipe($state['month']['rezepte'], $rzPrev, $add);
                }

                if ($gap) {
                    $state['shift']['dataGap'] = true;
                    $state['month']['dataGap'] = true;
                }

                if ($meterReset || !$meterOK) {
                    $state['shift']['meterReset'] = true;
                    $state['month']['meterReset'] = true;
                }

                $cursor = $segmentEnd;

                // Schichtwechsel
                if ($cursor >= $segment['end']) {
                    $crossed = true;
                    $state['shift']['boundaryUncertain'] = true;
                    $archive[] = MMRCalc::finish($state['shift']);
                    $state['shift'] = MMRCalc::newShift(MMRCalc::shift($cursor, $startHour), $cursor);
                    $state['shift']['boundaryUncertain'] = true;
                }

                // Monatswechsel
                if ($cursor >= $monthSeg['end']) {
                    $monthArchive[] = MMRCalc::finishMonth($state['month']);
                    $state['month'] = MMRCalc::newMonth(MMRCalc::month($cursor), $cursor);
                }
            }

            $state['shift']['ist'] += $deltaPieces;
            $state['month']['stueck'] += $deltaPieces;

            if ($recipeOn && $deltaPieces > 0) {
                MMRCalc::addRecipe($state['shift']['rezepte'], $rzPrev, ['stk' => $deltaPieces]);
                MMRCalc::addRecipe($state['month']['rezepte'], $rzPrev, ['stk' => $deltaPieces]);
            }

            if ($crossed) {
                $state['shift']['boundaryUncertain'] = true;
            }

            if ($counterReset) {
                $state['shift']['counterReset'] = true;
                $state['month']['counterReset'] = true;
                $this->LogMessage('Stückzähler-Rücksprung', KL_WARNING);
            }

            if ($meterReset) {
                $state['shift']['meterReset'] = true;
                $this->LogMessage('Energiezähler-Rücksprung', KL_WARNING);
            }

            if ($cycleOK && $oldCycle > 0 && abs($cycle - $oldCycle) > 0.001) {
                $state['shift']['cycleChanges']++;
            }

            $state['lastTime'] = $now;
            $state['lastCounter'] = $counter;
            $state['lastRates'] = $rates;
            $state['lastRecipe'] = $recipe;

            if ($meterOK) {
                $state['lastMeter'] = $meter;
            }

            if ($cycleOK) {
                $state['cycleMs'] = $cycle;
            }
        }

        // ----------------------------------------
        // Aktuelle Kennzahlen
        // ----------------------------------------

        $shift = $state['shift'];

        $ist = (int)$shift['ist'];
        $soll = (float)$shift['soll'];
        $energy = (float)$shift['energie'];

        $util = $soll > 0 ? 100.0 * $ist / $soll : 0.0;
        $diff = $ist - $soll;
        $rate = $cycleOK ? 3600000.0 / $cycle : 0.0;
        $specific = $ist > 0 ? $energy / $ist : 0.0;

        $se = MMRCalc::evaluate((float)$ist, $energy, $shift, $cfg);

        $month = $state['month'];
        $monthPieces = (int)$month['stueck'];
        $me = MMRCalc::evaluate((float)$monthPieces, (float)$month['strom'], $month, $cfg);

        $recipeShiftPieces = $recipeOn
            ? (int)(($shift['rezepte'][$recipe]['stk'] ?? 0))
            : 0;

        $totalParts = ['Strom Maschine'];
        if ($cfg['inclCool'] && $coolOn) {
            $totalParts[] = 'Kälte th' . ($pumpOn ? ' + Pumpe' : '');
        }
        if ($cfg['inclAir'] && ($hdOn || $ndOn)) {
            $totalParts[] = 'Druckluft el.';
        }
        $totalText = implode(' + ', $totalParts);

        // ----------------------------------------
        // Datenqualität
        // ----------------------------------------

        $flags = [];

        if ($firstRun || $shift['partial']) {
            $flags[] = 'Teilmessung';
        }
        if ($shift['boundaryUncertain']) {
            $flags[] = 'Schichtgrenze geschätzt';
        }
        if ($shift['dataGap']) {
            $flags[] = 'Messlücke / Sollzeit unsicher';
        }
        if ($shift['counterReset']) {
            $flags[] = 'Stückzähler zurückgesetzt';
        }
        if ($shift['meterReset']) {
            $flags[] = 'Energiezähler zurückgesetzt';
        }
        if (!$cycleOK) {
            $flags[] = 'Soll-Zykluszeit ungültig';
        }
        if (!$powerOK) {
            $flags[] = 'Leistung ungültig';
        }
        if ($coolOn && !$coolOK) {
            $flags[] = 'Kühlwasser-Messwerte ungültig';
        }
        if ($coolOK && $coolDT < 0) {
            $flags[] = 'Kühlwasser-Spreizung negativ';
        }
        if ($pumpOn && !$pumpOK) {
            $flags[] = 'Kühlwasser-Druck ungültig';
        }
        if (($hdOn && !$hdOK) || ($ndOn && !$ndOK)) {
            $flags[] = 'Druckluft-Messwert ungültig';
        }

        $fontMode = $this->ReadPropertyString('FontMode');
        $fontDir = IPS_GetKernelDir() . 'webfront' . DIRECTORY_SEPARATOR
            . 'user' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR;
        $fontFiles = $fontMode === 'lokal' ? MMRView::fontFiles($fontDir) : [];

        if ($fontMode === 'lokal' && !$fontFiles) {
            $flags[] = 'Schrift Poppins fehlt in ' . $fontDir;
        }

        $quality = $flags ? implode(' · ', $flags) : 'OK';

        // ----------------------------------------
        // Ausgabevariablen
        // ----------------------------------------

        $this->Out('Shift', (string)$shift['name']);
        $this->Out('Recipe', $recipe);
        $this->Out('RecipePieces', $recipeShiftPieces);
        $this->Out('Ist', $ist);
        $this->Out('Soll', round($soll, 3));
        $this->Out('Util', round($util, 2));
        $this->Out('Diff', round($diff, 2));
        $this->Out('Cycle', $cycleOK ? $cycle : 0.0);
        $this->Out('Rate', round($rate, 2));
        $this->Out('On', $on);
        $this->Out('Mode', $mode);
        $this->Out('ModeText', $modeInfo[0]);
        $this->Out('Power', $powerOK ? $power : 0.0);
        $this->Out('Energy', round($energy, 4));
        $this->Out('Specific', round($specific, 5));

        $this->Out('CoolPower', round($coolKW, 3));
        $this->Out('CoolDT', round($coolDT, 2));
        $this->Out('CoolShift', round($se['kaelteTh'], 4));
        $this->Out('CoolPerPc', round($se['kaelteThStk'] ?? 0.0, 5));
        $this->Out('PumpPerPc', round($se['pumpeStk'] ?? 0.0, 5));
        $this->Out('HDShift', round($se['hdNm3'], 4));
        $this->Out('HDPerPc', round($se['hdNlStk'] ?? 0.0, 3));
        $this->Out('NDShift', round($se['ndNm3'], 4));
        $this->Out('NDPerPc', round($se['ndNlStk'] ?? 0.0, 3));
        $this->Out('AirElPerPc', round($se['luftElStk'] ?? 0.0, 5));
        $this->Out('TotalPerPc', round($se['gesamtStk'] ?? 0.0, 5));

        $this->Out('MonthPieces', $monthPieces);
        $this->Out('MonthPower', round($me['strom'], 3));
        $this->Out('MonthPowerPerPc', round($me['stromStk'] ?? 0.0, 5));
        $this->Out('MonthTotal', round($me['gesamt'], 3));
        $this->Out('MonthTotalPerPc', round($me['gesamtStk'] ?? 0.0, 5));

        $this->Out('Quality', $quality);

        // ----------------------------------------
        // Verlauf
        // ----------------------------------------

        $point = [
            'ts' => $now,
            'shift' => $shift['key'],
            'ist' => $ist,
            'soll' => round($soll, 3),
            'util' => round($util, 2),
            'kW' => $powerOK ? $power : 0.0,
            'kWh' => $energy,
            'coolKW' => round($coolKW, 3),
            'hdLh' => round($hdLh, 1),
            'ndLh' => round($ndLh, 1),
            'on' => $on,
            'mode' => $mode
        ];
        if ($recipeOn) {
            $point['rz'] = $recipe;
        }
        $history[] = $point;

        $history = array_slice($history, -max(2, $this->ReadPropertyInteger('MaxHistoryPoints')));
        $archive = array_slice($archive, -max(1, $this->ReadPropertyInteger('MaxArchiveShifts')));
        $monthArchive = array_slice($monthArchive, -max(1, $this->ReadPropertyInteger('MaxArchiveMonths')));

        // ----------------------------------------
        // Anzeige
        // ----------------------------------------

        $lastHtmlAt = (int)($state['lastHtmlAt'] ?? 0);

        $refreshHtml = $firstRun
            || $this->GetBuffer('Markup') === ''
            || $lastHtmlAt <= 0
            || ($now - $lastHtmlAt) >= $this->ReadPropertyInteger('HtmlRefreshSeconds')
            || (string)($state['lastHtmlShift'] ?? '') !== (string)$shift['key']
            || ($state['lastHtmlOn'] ?? null) !== $on
            || ($state['lastHtmlMode'] ?? null) !== $mode
            || ($state['lastHtmlRecipe'] ?? '') !== $recipe;

        if ($refreshHtml) {

            $markup = MMRView::fontCss($fontMode, '/user/fonts/', $fontFiles)
                . MMRView::render([
                    'title' => $this->ReadPropertyString('Title'),
                    'cfg' => $cfg,
                    'now' => $now,
                    'startHour' => $startHour,
                    'shift' => $shift,
                    'se' => $se,
                    'me' => $me,
                    'month' => $month,
                    'monthPieces' => $monthPieces,
                    'archive' => $archive,
                    'monthArchive' => $monthArchive,
                    'monthTableRows' => $this->ReadPropertyInteger('MonthTableRows'),
                    'days' => $this->ReadPropertyInteger('Days'),
                    'util' => $util,
                    'ist' => $ist,
                    'soll' => $soll,
                    'diff' => $diff,
                    'cycle' => $cycle,
                    'cycleOK' => $cycleOK,
                    'rate' => $rate,
                    'on' => $on,
                    'modeInfo' => $modeInfo,
                    'power' => $power,
                    'powerOK' => $powerOK,
                    'energy' => $energy,
                    'coolOn' => $coolOn,
                    'coolOK' => $coolOK,
                    'pumpOn' => $pumpOn,
                    'hdOn' => $hdOn,
                    'ndOn' => $ndOn,
                    'tVL' => $tVL,
                    'tRL' => $tRL,
                    'coolDT' => $coolDT,
                    'coolDP' => $coolDP,
                    'flowM3h' => $flowM3h,
                    'coolKW' => $coolKW,
                    'hdLh' => $hdLh,
                    'ndLh' => $ndLh,
                    'totalText' => $totalText,
                    'quality' => $quality,
                    'history' => $history,
                    'recipeOn' => $recipeOn,
                    'recipe' => $recipe,
                    'recipeShiftPieces' => $recipeShiftPieces
                ]);

            $this->SetBuffer('Markup', $markup);

            // Offene Kacheln aktualisieren (Vergrößerung bleibt offen)
            if (method_exists($this, 'UpdateVisualizationValue')) {
                $this->UpdateVisualizationValue(json_encode(
                    ['html' => $markup],
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                ));
            }

            if ($this->ReadPropertyBoolean('HTMLBox')) {
                $this->Out('HTML', $this->BuildPage($markup));
            }

            $state['lastHtmlAt'] = $now;
            $state['lastHtmlShift'] = (string)$shift['key'];
            $state['lastHtmlOn'] = $on;
            $state['lastHtmlMode'] = $mode;
            $state['lastHtmlRecipe'] = $recipe;
        }

        // ----------------------------------------
        // Speichern
        // ----------------------------------------

        $this->WriteAttributeString('State', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->WriteAttributeString('Archive', json_encode($archive, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->WriteAttributeString('MonthArchive', json_encode($monthArchive, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->WriteAttributeString('History', json_encode($history, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        if ($this->GetStatus() !== 102) {
            $this->SetStatus(102);
        }

        return ($refreshHtml ? 'Dashboard aktualisiert: ' : 'Messwerte aktualisiert: ')
            . MMRView::num($util, 1) . ' %, '
            . MMRView::num($energy, 2) . ' kWh'
            . ($recipeOn ? ' · Rezept ' . $recipe : '')
            . ' · Monat ' . MMRView::opt($me['stromStk'], 3, 'kWh') . ' Strom/Stk, '
            . MMRView::opt($me['gesamtStk'], 3, 'kWh') . ' gesamt/Stk';
    }
}
