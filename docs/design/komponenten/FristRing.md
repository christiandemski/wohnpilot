# FristRing

Ring, der die verbleibenden Tage bis zu einer Frist zeigt, etwa dem letzten Kündigungstag eines Vertrags.

## Aufbau

- SVG 64 px: Spur 8 px in `paper` (auf `surface`), Bogen 8 px mit runden Enden, Start oben, im Uhrzeigersinn.
- Bogenlänge = verstrichener Anteil der Periode bis zur Frist.
- Farbe: `ok` bei mehr als 30 Tagen, `soon` darunter, `due` ab dem Stichtag.
- Mitte: Tage im Stil `num-l` (20 px), darunter „Tage“ 10 px `ink-muted`.
- In der Kachel darunter: Titel `body-strong` als Frage („Strom kündigen?“), Datum in `soon-text` bzw. `due`.

## Regeln

- Tage und Datum stehen immer als Text; der Ring ist nie die einzige Information.
- Der Ring ist dekorativ (`aria-hidden`), die Kachel als Ganzes ist ein Link zum Vertrag.
- Auf der Vertragsseite ergänzt das ausführliche Fristen-Lineal den Ring: Periodenbeginn, heute, Kündigungsstichtag, Laufzeitende.
