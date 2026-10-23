# Kennzahlkachel

Bento-Kachel mit einer Kennzahl: Icon-Kachel des Moduls, Label, große Zahl mit Einheit, Trend und Mini-Balken der letzten Perioden.

## Aufbau

- Fläche `surface`, `radius-lg`, Innenabstand `space-4`, Abstand innen 10 px; belegt eine Bento-Spalte.
- Oben links Icon-Kachel 36 px (`radius-xs`, `module-<name>-tint`, Icon `module-<name>-ink`), oben rechts Trend im Stil `label` (`ok` wenn besser, `due` wenn deutlich schlechter, sonst `ink-muted`).
- Label `small` in `ink-muted`, Zahl im Stil `kpi`, Einheit 14 px `ink-muted`.
- Mini-Balken: sechs Perioden, vergangene in `line`, die aktuelle in `accent`, Radius 4 px, Höhe 32 px.

## Regeln

- Die ganze Kachel führt zur Detailseite.
- Trend immer als Zahl mit Vorzeichen („−8 %“), nie nur als Farbe oder Pfeil.
- Mini-Balken haben ein `aria-label`, das die Aussage in Worten nennt.
