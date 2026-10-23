# Button

Pill-Schaltflächen für Aktionen: Petrol für die Hauptaktion, Weiß für Nebenaktionen, Invers für kompakte Aktionen in hellen Kacheln, rund für reine Icons.

## Varianten

- **Primär:** `accent` mit `on-accent`, `radius-full`, Höhe `button-height` (in Karten) oder `button-height-l` (Fußbereich), Stil `button`. Optional Pfeil-Icon rechts.
- **Sekundär:** `surface` mit `ink`, ohne Rahmen auf `paper`; auf `surface` mit 1 px `line`.
- **Invers:** `inverse` mit `on-inverse`, Höhe 40–44 px, Stil `small` 600. Für „Ablesen“, „Erinnerung anlegen“.
- **Icon rund:** 44 oder 56 px Kreis in `surface`, Icon `ink`, Pflicht-`aria-label`.

## Regeln

- Eine Primär-Schaltfläche pro Bildschirm.
- Hover Primär: `accent-strong`. Fokus: 2 px Outline `accent` mit 2 px Abstand.
- Navigation ist ein `<a>`, eine Aktion ein `<button>`.
- Im Fußbereich: runde Icon-Schaltfläche links, Primär füllt den Rest.
