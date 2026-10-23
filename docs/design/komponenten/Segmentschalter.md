# Segmentschalter

Pill-Schalter mit zwei bis vier gleich breiten Optionen, etwa Monate · Jahre · Kosten über einem Diagramm.

## Aufbau

- Spur `line`, `radius-full`, Innenabstand 4 px; Optionen gleich breit im Raster.
- Gewählt: `surface` mit `ink`, 600; nicht gewählt: transparent mit `ink-muted`, 500. Höhe 40 px, mit Spur 48 px.
- `role="tablist"` mit `role="tab"` und `aria-selected`; Pfeiltasten wechseln.
