# Wohnpilot

Wohnpilot ist eine selbst gehostete Web-App (PWA) für alle haushaltsrelevanten Daten eines Mietobjekts: Zählerstände, Verträge und Fristen, Termine, Wartung, Mängel, Wissen. Startobjekt ist ein gemietetes Einfamilienhaus; dieselbe App muss ohne Umbau für eine Mietwohnung funktionieren. Die Daten liegen standardmäßig als Markdown-Notizen in der eigenen Nextcloud (`https://cloud.codekorb.de`).

Ausführliches Konzept: `docs/konzept.md`. Designsystem: `docs/design/README.md`.

## Arbeitsweise

- **Nur die aktuelle Ausbaustufe bauen** (siehe `docs/konzept.md`, Abschnitt Ausbaustufen). Keine Vorgriffe auf spätere Stufen, aber Datenmodell und Schnittstellen so anlegen, dass sie nicht im Weg stehen.
- Vor größeren Entscheidungen, die im Konzept als offene Frage stehen (Frontend-Framework, Backend-Ort, BearID-Anbindung), nachfragen statt raten.
- Kleine, nachvollziehbare Commits. Jede Stufe endet damit, dass echte Daten eingetragen werden können.
- UI-Texte auf Deutsch, Du-Form. Code, Bezeichner und Commit-Messages auf Englisch.

## Leitprinzipien (nicht verhandelbar)

1. **Nextcloud ist die Quelle der Wahrheit** (beim Nextcloud-Speicher). Der SQLite-Index im Backend ist nur Cache und muss jederzeit aus den Notizen neu aufgebaut werden können.
2. **Verlustfreies Lesen und Schreiben:** Unbekannte Frontmatter-Felder, freier Text und manuelle Änderungen aus der Notes-App bleiben beim Speichern erhalten.
3. **Abgeleitetes wird berechnet, nie gespeichert:** Fristende, Verbrauch, Fälligkeit, Hochrechnung.
4. **Keine Geheimnisse in Notizen oder im Repo:** Passwörter und PINs bleiben in 1Password. Das Nextcloud-App-Passwort liegt nur im Backend (Umgebungsvariable), nie im Browser.
5. **Speicher über Adapter:** Eine gemeinsame Schnittstelle (`StorageAdapter`), zuerst nur der Nextcloud-Adapter. SQLite- und MySQL-Adapter kommen später.
6. **Mehrbenutzer vorbereitet:** Jeder Eintrag trägt `erstellt_von` / `geaendert_von` (BearID-Kennung); Haushalt und Objekt sind von Anfang an eigene Entitäten.

## Datenformat

- Ein Eintrag = eine Markdown-Notiz mit YAML-Frontmatter; Ordner = Module (Struktur in `docs/konzept.md`, Abschnitt Datenformat).
- Pflichtfelder: `typ`, `id` (stabiler Slug, auch für QR-Codes), bei objektgebundenen Einträgen `objekt`.
- Datumsangaben im Frontmatter und in Tabellen immer ISO (`2026-10-24`).
- Verläufe (Ablesungen, Preis-Historie) als Markdown-Tabelle im Text, neueste Zeile oben.
- Beispiel-Notizen liegen in `fixtures/Wohnpilot/`. Parser-Tests müssen gegen diese Dateien laufen: einlesen, unverändert zurückschreiben, Byte-gleich vergleichen.

## Nextcloud

- Notes API (`/index.php/apps/notes/api/v1/notes`) für Notizen, WebDAV für Anhänge und Ordner.
- Vor jedem Schreiben ETag prüfen; bei Konflikt nicht überschreiben, sondern Zusammenführen anbieten.
- Eigener Ordner `Wohnpilot/`, eigenes App-Passwort nur für diese App.

## Design

- Verbindlich ist `docs/design/`: Tokens in `tokens.json`, daraus generiert `tokens.css`. **Keine fest codierten Farben, Abstände oder Radien** – immer die CSS-Variablen.
- Schriften Geist und Geist Mono **selbst hosten** (woff2 im Projekt, OFL), nicht von Google Fonts laden.
- Zahlen immer in `--font-num` mit `font-variant-numeric: tabular-nums`, deutsches Zahlenformat (`23 702,0`).
- Komponenten-Referenzen mit Aufbau, Zuständen und Regeln: `docs/design/komponenten/*.md` und `*.html` (im Browser öffnen).
- Mockups: `docs/mockups/` (gewählte Richtung) und `docs/mockups/erster-entwurf/` (nur für Struktur und Inhalte, nicht für den Look).
- Icons: Lucide, Strichstärke 1,75. Keine Emoji.
- Hell und Dunkel: `data-theme` am `<html>` plus `prefers-color-scheme`; Kiosk immer dunkel.

## Barrierefreiheit

- Echte `<button>`, `<a>`, `<input>` mit `<label>`; Icon-Schaltflächen mit `aria-label`.
- Touch-Ziele mindestens 44 px; Kontrast Text 4.5:1 in beiden Themes.
- Status nie nur über Farbe: immer Wort oder Icon dazu.

## Fertig heißt

- Funktioniert auf dem Handy (390 px) und am Desktop, hell und dunkel.
- Tests für Parser, Fristberechnung und Verbrauchsberechnung grün.
- Keine Zugangsdaten im Repo; `.env.example` aktuell.
- `docs/konzept.md` angepasst, wenn sich eine Entscheidung geändert hat.
