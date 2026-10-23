# StatusChip

Kleine Pill für Status oder Modulzugehörigkeit, immer mit Wort, nie nur Farbe.

## Varianten

- **Überfällig:** `due-tint` mit `due` („Überfällig · 2 Tage“); auf `inverse` Text `due-on-inverse` auf rgba(229, 72, 77, 0.2).
- **Bald:** `soon-tint` mit `soon-text` („noch 9 Tage“).
- **OK:** `ok-tint` mit `ok` („Heute erfasst“).
- **Modul:** `module-<name>-tint` mit `module-<name>-ink` („Zähler · Keller“).
- **Neutral/Datum:** `paper` mit `ink` („Di 27.“).

## Maße

Stil `label`, Innenabstand 4–6 × 10 px, `radius-full`. Chips sind keine Schaltflächen; was antippbar ist, braucht mindestens `touch-min` Höhe.
