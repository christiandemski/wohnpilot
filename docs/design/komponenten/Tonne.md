# Tonne

Gefülltes Tonnen-Icon in der Farbe der jeweiligen Tonne, damit Müll-Termine auf einen Blick erkennbar sind.

## Aufbau

- 24 × 24 Raster: Deckel als Rechteck (x 3,5, y 4, 17 × 3,5, Radius 1,25), Korpus leicht konisch mit runden unteren Ecken, zwei senkrechte Rillen.
- Füllung `bin-rest`, `bin-bio`, `bin-papier`, `bin-gelb` oder `bin-glas`.
- Auf hellem Grund Umriss und Rillen 1,25 px in `ink`, damit Gelb und helle Töne nicht verschwinden; auf dunklem Grund (Kiosk) ohne Umriss, Rillen in `paper`.
- In Listen in einer 40-px-Kachel (`radius-sm`) mit Grund `bin-tint`; im Kiosk 48 px ohne Kachel.

## Regeln

- Farben sind Standardwerte und pro Haushalt in den Einstellungen änderbar (Kommunen nutzen andere Farben).
- Die Farbe ersetzt nie den Namen: „Gelbe Tonne“, „Restmüll“ stehen immer als Text daneben.
- Bei Vollservice zeigt die Zeile „Abholung · Vollservice“ statt einer Erinnerung zum Rausstellen.
