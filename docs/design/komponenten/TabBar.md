# TabBar

Schwebende dunkle Navigation am unteren Rand mit vier Zielen und der Plus-Schaltfläche in der Mitte.

## Aufbau

- 16 px vom Rand links und rechts, 12 px vom unteren Rand, Höhe `tabbar-height` (60 px), `radius-full`, Fläche `inverse` mit 94 % Deckkraft, Schatten `shadow-tabbar`.
- Ziele: Übersicht · Zähler · Plus · Zuhause · Mehr, alle gleich breit.
- Jedes Ziel zeigt Icon (20 px) und Wort darunter (11 px, `font-weight` 600); die ganze Spalte ist das Tippziel (mindestens `touch-min`).
- Inaktiv in `on-inverse-muted`; aktiv in `on-inverse` mit heller Pill hinter dem Icon (`on-inverse` mit 16 % Deckkraft).
- Plus: Kreis `fab` (36 px) in `accent`, Icon `on-accent`, darunter das Wort „Erfassen“ wie bei den anderen Zielen. Ein Tipp öffnet das Erfassen-Sheet: Zählerstände ablesen, Mangel melden, Wartung erledigt, Termin, Frist, Gerät; die zuletzt genutzte Wahl steht oben.
- „Zuhause“ ist aktiv für alles am Haus (Mietvertrag, Wartung, Termine und Müll, Mängel, Geräte), „Mehr“ für Verträge, Fristen und Einstellungen.

## Regeln

- Der Seiteninhalt endet `tabbar-height` + 12 px + 24 px über dem Rand, damit nichts unter der Leiste verschwindet.
- `aria-current="page"` am aktiven Ziel.
- Auf dem Desktop ersetzt eine linke Navigation die TabBar.
