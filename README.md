# Maschinenmonitor – IP-Symcon-Modul

Schichtauslastung (Ist / dynamisches Soll), Energie je Kanister, Prozesskälte,
Druckluft, Monatskennzahlen und **Rezepterfassung** – als Kachel für die
Kachel-Visualisierung mit **vergrößerbaren Bereichen und Diagrammen**.

Voraussetzung: IP-Symcon ab 7.0 (HTML-SDK für Kacheln).

## Installation

**Variante A – Module Control (empfohlen)**
1. Den Ordner `Maschinenmonitor` in ein (privates) Git-Repository legen.
2. Konsole → Kern Instanzen → *Modules* → **+** → Repository-URL eintragen.

**Variante B – lokal**
1. Den kompletten Ordner `Maschinenmonitor` (mit `library.json`) in das
   Verzeichnis `modules` des Symcon-Datenordners kopieren
   (Linux: `/var/lib/symcon/modules/`, Windows: `C:\ProgramData\Symcon\modules\`).
2. Symcon-Dienst neu starten.

Danach: Objektbaum → **Instanz hinzufügen** → „Maschinenmonitor“.

## Einrichtung

| Bereich | Inhalt |
|---|---|
| Maschine | Stückzähler, Soll-Zykluszeit (ms), AN/AUS, Betriebsart (1–5), Leistung (kW), Energiezähler (kWh) |
| Rezept | Variable mit dem aktuell ausgewählten Rezept (String oder Integer mit Profil) |
| Prozesskälte | VL/RL-Temperatur, Volumenstrom (+ Einheit), optional Drücke für den Pumpenanteil |
| Druckluft | HD / ND in Nl/h, spezifischer Strombedarf in kWh/Nm³ |
| Gesamtenergie | Kälte und/oder Druckluft in „Energie gesamt je Kanister“ einrechnen |
| Schichten & Anzeige | Beginn Frühschicht (3 × 8 h), Schriftart, Tabellengrößen, optional HTML-Box |
| Erweitert | Messintervall, Archivgrößen usw. (Werte wie im bisherigen Skript) |

Leere Felder bei Kälte/Druckluft = Messung nicht vorhanden.

### Kachel anlegen
Kachel-Visualisierung → Kachel hinzufügen → **die Maschinenmonitor-Instanz**
auswählen. Die Anzeige ist für 1920×1080 gebaut und skaliert auf die
Kachelgröße; im Hochformat (Handy) werden die Bereiche untereinander gezeigt.

### Vergrößern
Jeden Bereich antippen bzw. anklicken → er füllt die ganze Kachel.
Schließen mit ✕, Tippen in den Bereich oder Esc. Die Vergrößerung bleibt bei
der automatischen Aktualisierung offen.

In der Vergrößerung zusätzlich:
- **Verlauf**: Achsenbeschriftung, Stundenraster, Rezeptwechsel mit Namen,
  Fadenkreuz mit Messwerten (Maus bzw. Finger).
- **Schichtauslastung**: Restzeit, Prognose bei Schichtende, Auslastung im Schichtverlauf.
- **Aktuelle Schicht / Monat / 7 Tage**: Rezepttabelle mit Laufzeit, Kanister,
  Auslastung, Strom-, Gesamt-, Kälte- und Druckluftbedarf je Kanister.
- **Schichttabelle**: Hauptrezept je Schicht.
- **Monatsübersicht**: Absolutwerte und Hauptrezept je Monat.

### Rezepterfassung
Bei jedem Rezeptwechsel rechnet das Modul sofort. Stück, Strom, Medien, Laufzeit
und Soll seit dem letzten Lauf werden dem Rezept zugeordnet, das in dieser Zeit
aktiv war – je Schicht, je Monat und in der 7-Tage-Übersicht.

### Daten aus dem alten Skript übernehmen
Instanz → Aktionen → „Daten aus dem bisherigen Skript übernehmen“ → altes Skript
wählen → **Daten übernehmen**. Danach Timer und Ereignisse des alten Skripts
deaktivieren (oder das Skript löschen).

## Öffentliche Funktionen

```php
MMR_Update(int $InstanzID): string;               // sofort messen und Anzeige aktualisieren
MMR_ImportFromScript(int $InstanzID, int $SkriptID): string;
MMR_ResetData(int $InstanzID): string;            // alle gesammelten Daten löschen
```

## Unterschiede zum Skript
- Interne Daten (Zustand, Archive, Verlauf) liegen in Instanz-Attributen statt in
  versteckten Variablen.
- Ereignisse werden über Nachrichten (VM_UPDATE) statt über Ereignis-Objekte ausgelöst.
- Ausgabevariablen haben Profile (Einheiten); Variablen nicht konfigurierter Medien
  werden nicht angelegt.
- Die alte „responsive“ Ansicht entfällt; die FullHD-Ansicht hat eine eigene
  Hochformat-Darstellung für Handy und Tablet.
- Die HTML-Box-Variable ist optional (für WebFront). Das Vergrößern funktioniert
  in der Kachel-Visualisierung; im alten WebFront werden keine Skripte ausgeführt.
