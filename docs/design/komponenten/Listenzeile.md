# Listenzeile

Eine Zeile in Listen wie „Diese Woche“: Icon-Kachel, Titel und Meta, rechts entweder ein Datum-Chip oder ein Erledigt-Kreis.

## Aufbau

- Mindesthöhe 52 px, Abstand 12 px, in einer `surface`-Karte mit `radius-lg` und `space-4` Innenabstand; Kartentitel im Stil `section` mit „Alle“-Link rechts.
- Icon-Kachel 40 px, `radius-sm`: Modul-Tint mit Modul-Icon oder `bin-tint` mit Tonne.
- Titel `body-strong`, Meta `small` in `ink-muted` („Wartung · jährlich“, „Abholung · Vollservice“).
- Rechts: Datum-Chip (`paper`, `label`, `radius-full`) für Termine, oder Erledigt-Kreis 44 px (Ring 2 px `line-strong`) für Aufgaben.

## Verhalten

- Tippen auf den Kreis hakt ab: Kreis füllt sich `accent` mit weißem Haken, die Zeile zeigt kurz „Erledigt · CD“ und verschwindet nach 3 Sekunden mit Rückgängig-Option.
- Tippen auf den Rest der Zeile öffnet die Detailseite.
- Der Kreis hat ein `aria-label` mit Aufgabe und Aktion.
