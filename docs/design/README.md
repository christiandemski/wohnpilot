# Wohnpilot

Dein Zuhause im Blick – Zähler, Fristen, Termine. Wohnpilot verbindet ein modernes App-Layout (Bento-Kacheln, große runde Karten, schwebende Tab-Leiste) mit der Wärme eines gut geführten Hausbuchs: Papiergrund, Petrol als Aktionsfarbe und Zahlen, die aussehen wie am echten Zähler. Es funktioniert für Haus, Wohnung oder WG gleich.

## Grundsätze

- **Eine Hauptsache pro Bildschirm.** Die Übersicht beginnt mit genau einer dunklen „Als Nächstes“-Karte; alles andere ordnet sich darunter in Kacheln.
- **Flächen statt Linien.** Weiße Karten (`surface`) auf Papier (`paper`), große Radien, fast keine Rahmen. Schatten nur an Tab-Leiste und Bottom Sheet.
- **Farbe heißt etwas.** `accent` = Handlung oder aktueller Wert, `ok`/`soon`/`due` = Dringlichkeit, `module-*` = Modul. Neutrale Flächen sonst.
- **Zahlen groß und ehrlich.** Stände, Verbräuche, Tage in `--font-num` mit gleich breiten Ziffern, groß gesetzt, Einheit klein und gedämpft daneben.
- **Vorschlagen statt nur anzeigen.** Wo die App etwas ausrechnen kann, sagt sie, was zu tun ist („Mit 91 € statt 85 € Abschlag gehst du auf null“).

## Sprache und Ton

- Du-Form, kurz, freundlich, deutsch. Konkrete Zahlen und Daten.
- Überschrift = was ansteht („Zählerstände ablesen“), Chip = wie dringend („Überfällig · 2 Tage“), Schaltfläche = was passiert („Ablese-Runde starten“).
- Warnungen ohne Schuld: „Kleiner als der letzte Stand“ statt „Fehlerhafte Eingabe“.
- Daten `TT.MM.` in Fließtext, `TT.MM.JJJJ` in Tabellen; deutsches Komma, Punkt als Tausendertrenner (`23.702,0`).

## Farben

| Rolle | Tokens | Regel |
| --- | --- | --- |
| Grund | `paper`, `surface` | Seite auf `paper`, Inhalt auf `surface`-Kacheln. |
| Text | `ink`, `ink-muted` | Nur diese zwei für Text auf hellen Flächen, außer Status. |
| Dunkle Fläche | `inverse`, `on-inverse`, `on-inverse-muted` | Als-Nächstes-Karte, Tab-Leiste, Invers-Schaltfläche, Zahlen-Pill. Höchstens eine große dunkle Karte pro Bildschirm. |
| Aktion | `accent`, `accent-strong`, `accent-tint`, `on-accent` | Hauptaktion als Petrol-Pill; der aktuelle Balken im Diagramm. |
| Status | `ok`, `ok-tint`, `soon`, `soon-text`, `soon-tint`, `due`, `due-tint`, `due-on-inverse` | Immer mit Icon oder Wort. `soon` ist Fläche, `soon-text` ist Schrift. |
| Rollenzähler | `counter-face`, `counter-decimal`, `counter-digit` | Nur in den Ziffernwalzen. |
| Module | `module-<name>-tint`, `module-<name>-ink` | Icon-Kachel (tint) mit Icon (ink), Modul-Chips. Nie für Aktionen. |
| Linien | `line`, `line-strong` | Trennlinien in Listen, vergangene Balken, offene Checkbox-Ringe. |
| Tonnen | `bin-rest`, `bin-bio`, `bin-papier`, `bin-gelb`, `bin-glas`, `bin-tint` | Füllfarbe des Tonnen-Icons, damit man die Tonne auf einen Blick erkennt. Pro Haushalt anpassbar, weil Kommunen andere Farben nutzen. |

Kontrast: Text 4.5:1 in beiden Themes; Icons und Ringe 3:1. Dunkel folgt `prefers-color-scheme` und ist umschaltbar; die Kiosk-Ansicht ist immer dunkel.

## Typografie

- **Geist** (`--font-ui`, 400/500/600/700) für alle Texte. Titel 700 mit leicht negativem Zeichenabstand.
- **Geist Mono** (`--font-num`, 500/600) für alle Zahlen, Nummern und Daten in Tabellen, mit `font-variant-numeric: tabular-nums`.
- In der App die Schriften selbst hosten (woff2, OFL-Lizenz), nicht über Google Fonts laden.

## Form und Raster

- Raster 4 px (`space-1` … `space-8`). Seitenrand `space-4`, Abstand zwischen Kacheln `space-3`.
- Radien: `radius-lg` für Karten und Kacheln, `radius-xl` für die Als-Nächstes-Karte und den Rollenzähler, `radius-sm` für Ziffernwalzen und Icon-Kacheln, `radius-full` für Schaltflächen, Chips, Segment-Schalter und Tab-Leiste.
- Bento: zwei Spalten gleicher Breite; eine Kachel kann beide Spalten belegen. Kacheln sind ganz antippbar.
- Bedienelemente mindestens `touch-min`. Haupt-Schaltflächen `button-height` bzw. `button-height-l`.

## Ikonografie

- [Lucide](https://lucide.dev), Strichstärke 1,75 (2 bis 2,25 für Plus, Pfeile, Status), `stroke="currentColor"`, runde Enden.
- Module zeigen ihr Icon in einer Kachel: 36–52 px, `module-<name>-tint` als Grund, Icon in `module-<name>-ink`.
- Zähler-Icon: Blitz (Strom), Tropfen (Wasser), Flamme (Gas), Thermometer (Wärme), Sonne (PV).
- Dekorative Icons `aria-hidden="true"`, Icon-Schaltflächen mit `aria-label`. Keine Emoji.

## Komponenten

- **Button** – Petrol-Pill, weiße Pill, Invers-Pill, runde Icon-Schaltfläche.
- **NextCard** – die dunkle „Als Nächstes“-Karte mit Chip und Haupt-Aktion.
- **Kennzahlkachel** – Bento-Kachel mit Icon-Kachel, Zahl, Trend und Mini-Balken.
- **FristRing** – Ring mit verbleibenden Tagen bis zur Kündigungsfrist.
- **Rollenzaehler** – Zählerstand-Eingabe mit dunklen Walzen und roter Nachkommastelle.
- **Listenzeile** – Icon-Kachel, Titel, Meta, rechts Datum-Chip oder Erledigt-Kreis.
- **StatusChip** – Chips für Status und Module.
- **Tonne** – gefülltes Tonnen-Icon in der Farbe der Tonne für Müll-Termine.
- **Segmentschalter** – Pill-Auswahl für Zeiträume.
- **TabBar** – schwebende dunkle Navigation mit Plus-Schaltfläche.

## Layout je Gerät

- **Handy:** eine Spalte, Tab-Leiste schwebt 24 px über dem Rand; Inhalt endet 120 px über dem Rand.
- **Desktop:** linke Navigation als Liste der Module mit Icon-Kacheln, Inhalt als Bento-Raster mit drei bis vier Spalten.
- **Kiosk:** immer Dunkel, drei große Kacheln, keine Bedienung, Zahlen ab 48 px.

## Im Code verwenden

Alle Tokens als CSS-Variablen (`var(--paper)`, `var(--radius-lg)`, `var(--font-num)`), Dunkel über `[data-theme="dark"]` oder `prefers-color-scheme`. Die Komponenten werden im Projekt gebaut; die Vorschauen hier sind die Referenz für Aufbau, Zustände und Maße.
