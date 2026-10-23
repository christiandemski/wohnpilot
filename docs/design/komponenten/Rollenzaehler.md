# Rollenzaehler

Eingabe eines Zählerstands als Ziffernwalzen wie am echten Zähler, vorbelegt mit dem letzten Stand; das Herzstück der Ablese-Runde.

## Aufbau

- Karte `surface`, `radius-xl`, Inhalt zentriert.
- Je Stelle: Pfeil hoch (44 × 40), Walze 46 × 72 in `counter-face`, `radius-sm`, Ziffer `counter-digit` im Stil `stand`, Pfeil runter. Leichte Innenschattierung oben und unten als Walzen-Andeutung.
- Nachkommastellen nach einem Komma in `ink-muted`, Walze `counter-decimal`.
- Darunter: Zahlen-Pill (`inverse`, `on-inverse`, `num-l`) mit der Differenz, daneben „seit 01.10.“; dann das Prüfergebnis.
- Unter der Karte zwei kleine Kacheln: letzter Stand und Verbrauch pro Tag.

## Verhalten

- Pfeile zählen eine Stelle 0–9 rundum; Tippen auf eine Walze öffnet die Zifferntastatur für den ganzen Wert.
- Stellenzahl und Nachkommastellen kommen aus dem Zähler.
- Prüfung: kleiner als der letzte Stand → `due` „Kleiner als der letzte Stand“; über dem Üblichen → `soon-text` „Ungewöhnlich hoch“; sonst `ok` „Passt zu deinem Verbrauch“. Speichern bleibt möglich und fragt bei Warnung nach dem Anlass (z. B. Zählerwechsel).

## Barrierefreiheit

- Pfeile als `<button>` mit `aria-label` „Stelle 3 erhöhen“; Gruppe mit `aria-label="Zählerstand"`.
- Prüfergebnis mit `role="status"`.
