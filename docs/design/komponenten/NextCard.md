# NextCard

Die dunkle „Als Nächstes“-Karte oben auf der Übersicht: zeigt genau die eine Sache, die jetzt am dringendsten ist, mit direkter Aktion.

## Aufbau

- Fläche `inverse`, `radius-xl`, Innenabstand `space-5`, Abstand innen 14 px.
- Kopfzeile: „Als Nächstes“ in `on-inverse-muted` links, Status-Chip rechts (überfällig: Text `due-on-inverse` auf rgba(229, 72, 77, 0.2); bald: `soon` auf rgba(183, 121, 31, 0.25)).
- Titel im Stil `title` in `on-inverse`, darunter eine Meta-Zeile mit Umfang und Dauer in `on-inverse-muted`.
- Primär-Schaltfläche über die volle Breite, Höhe `button-height`, mit Pfeil.

## Regeln

- Genau eine NextCard pro Übersicht. Die Auswahl rechnet die App: überfällig vor bald, Frist vor Aufgabe.
- Ist nichts fällig, zeigt sie die nächste Sache mit Datum oder „Alles erledigt“ mit `ok`-Haken und ohne Schaltfläche.
