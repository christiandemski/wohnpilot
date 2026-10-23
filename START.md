# Start mit Claude in der IDE

1. Diesen Ordner als neues Repository anlegen (z. B. `wohnpilot`) und in der IDE öffnen.
2. Claude Code starten und den folgenden Prompt einfügen:

```
Lies CLAUDE.md, docs/konzept.md und docs/design/README.md.

Wir bauen Wohnpilot, Ausbaustufe 1 (Fundament und Zähler).

Bevor du Code schreibst:
1. Stell mir die offenen Fragen aus docs/konzept.md, die Stufe 1 betreffen
   (Frontend-Framework, wo das Backend läuft, wie BearID angebunden wird).
2. Schlag danach einen Plan für Stufe 1 vor: Projektstruktur, Reihenfolge,
   welche Tests du schreibst. Warte auf mein Okay.

Reihenfolge danach:
- Parser für Frontmatter und Markdown-Tabellen mit Roundtrip-Tests gegen fixtures/
- StorageAdapter-Schnittstelle und Nextcloud-Adapter (Notes API, ETag)
- Backend mit BearID-Login und App-Passwort aus .env
- Frontend: tokens.css einbinden, Übersicht, Ablese-Runde mit Rollenzähler,
  Zähler-Detail mit Diagramm – nach docs/mockups/ und docs/design/komponenten/
- PWA installierbar machen
```

3. Nach jeder Stufe: echte Zählerstände eintragen, dann die nächste Stufe beauftragen.

## Was im Paket liegt

| Pfad | Inhalt |
| --- | --- |
| `CLAUDE.md` | Regeln und Leitplanken für Claude |
| `docs/konzept.md` | Vollständiges Konzept mit Modulen, Datenformat, Architektur, Ausbaustufen |
| `docs/design/` | Designsystem: README, `tokens.json`, `tokens.css`, Komponenten (`.md` + `.html`) |
| `docs/mockups/` | Mockups der gewählten Richtung; `erster-entwurf/` für Struktur von Erfassen, Vertrag, Einrichtung, Kiosk |
| `fixtures/Wohnpilot/` | Beispiel-Notizen wie in Nextcloud, für Parser-Tests und lokale Entwicklung |

Die Online-Versionen (Konzept, Designsystem, Leinwand) bleiben in Claude und lassen sich dort weiter bearbeiten.
