# TabBar

Schwebende dunkle Navigation am unteren Rand mit vier Zielen und der Plus-Schaltfläche in der Mitte.

## Aufbau

- 16 px vom Rand links und rechts, 24 px vom unteren Rand, Höhe `tabbar-height`, `radius-full`, Fläche `inverse` mit 94 % Deckkraft, Schatten `shadow-tabbar`.
- Ziele: Übersicht · Zuhause · Plus · Suche · Mehr.
- Aktives Ziel als weiße Pill (`surface` bzw. `on-inverse` als Grund, Text `ink`) mit Icon und Wort; inaktive nur Icon in `on-inverse-muted`, jeweils 48 × 48 mit `aria-label`.
- Plus: Kreis `fab` in `accent`, Icon `on-accent`, öffnet das Erfassen-Sheet.

## Regeln

- Der Seiteninhalt endet 120 px über dem Rand, damit nichts unter der Leiste verschwindet.
- `aria-current="page"` am aktiven Ziel.
- Auf dem Desktop ersetzt eine linke Navigation die TabBar.
