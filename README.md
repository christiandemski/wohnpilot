# Wohnpilot

Selbst gehostete PWA für alle haushaltsrelevanten Daten eines Mietobjekts. Die Daten liegen als Markdown-Notizen in der eigenen Nextcloud. Konzept: [`docs/konzept.md`](docs/konzept.md), Designsystem: [`docs/design/README.md`](docs/design/README.md), Regeln für die Arbeit am Code: [`CLAUDE.md`](CLAUDE.md).

Stand: **Ausbaustufe 1** – Fundament und Zähler.

## Aufbau

| Ordner | Inhalt |
| --- | --- |
| `backend/` | PHP 8.3+ mit Slim 4: JSON-API unter `/api`, Login, Nextcloud-Anbindung, Berechnungen, SQLite-Index |
| `backend/src/Note/` | Verlustfreier Parser für Frontmatter und Markdown-Tabellen |
| `backend/src/Storage/` | `StorageAdapter` mit Nextcloud-Notes- und Dateisystem-Adapter |
| `backend/src/Domain/` | Verbrauch, Plausibilität, Fälligkeit, Kosten-Hochrechnung – nur berechnet, nie gespeichert |
| `backend/src/Auth/` | Anmeldung: BearID-Cookie, E-Mail-Konten als Notzugang, Entwicklungs-Login |
| `frontend/` | Svelte 5 + Vite + TypeScript, baut nach `backend/public/` |
| `fixtures/Wohnpilot/` | Beispiel-Notizen; Parser-Tests lesen und schreiben sie byte-genau |

## Entwickeln

Voraussetzungen: PHP 8.3+ (mit `pdo_sqlite`, `mbstring`, `curl`), Composer, Node 22+.

```sh
cp .env.example .env          # für lokal: APP_ENV=dev, STORAGE=filesystem,
                              # DEV_LOGIN_USER=christian, WOHNPILOT_USERS=christian,
                              # APP_URL=http://localhost:5173
cd backend && composer install && composer seed-dev && composer serve   # API auf :8080
cd frontend && npm install && npm run dev                               # App auf :5173
```

`composer seed-dev` kopiert die Fixtures nach `backend/var/notes`; mit `STORAGE=nextcloud` arbeitet die lokale App direkt mit deiner Nextcloud.

### Tests

```sh
cd backend && composer test        # Parser, Berechnungen, Adapter, API
cd backend && composer test-live   # gegen die echte Nextcloud aus .env (legt Notizen unter Wohnpilot/_test an und löscht sie)
cd frontend && npm test            # Zahlenformat, Rollenzähler, Plausibilität
cd frontend && npm run check       # Svelte- und TypeScript-Prüfung
```

## Betrieb auf Netcup Webhosting

1. Lokal bauen: `cd frontend && npm run build` (legt die App nach `backend/public/`), `cd backend && composer install --no-dev --optimize-autoloader`.
2. Den Ordner `backend/` hochladen, z. B. nach `/wohnpilot/`. **Document Root der (Sub-)Domain auf `/wohnpilot/public` stellen**, damit `.env`, `var/` und `vendor/` nicht erreichbar sind.
3. `.env` nach `/wohnpilot/.env` (neben `composer.json`) mit `APP_ENV=prod`, `APP_URL=https://wohnpilot.codekorb.de`, Nextcloud-App-Passwort, `BEARID_PUBLIC_KEY_FILE` (Pfad zu `bearid-guard/public.key` im Webspace) und `WOHNPILOT_USERS` (deine E-Mail-Adresse). `DEV_LOGIN_USER` bleibt leer.
4. PHP-Version im Panel auf 8.3 oder neuer; `pdo_sqlite` muss aktiv sein. Der Ordner `var/` muss beschreibbar sein (Index und Sessions-Cache).
5. **BearID:** Wohnpilot auf der Subdomain `wohnpilot.codekorb.de` betreiben (nur dort bekommt es das Cookie `bearid`). In BearID unter Tools ein Tool mit Kürzel `wohnpilot` und Adresse `https://wohnpilot.codekorb.de` anlegen. In Plesk bei der Subdomain `open_basedir` auf `{WEBSPACEROOT}{/}{:}{TMP}{/}` stellen, damit PHP `bearid-guard/public.key` lesen darf.
6. **Notzugang:** per SSH im Ordner `/wohnpilot` `php bin/account.php add <deine-email> christian` ausführen und ein Passwort mit mindestens 12 Zeichen setzen. `var/accounts.sqlite` gehört ins Backup.
7. Optional Cronjob, damit Änderungen aus der Notes-App auch ohne Aufruf eingelesen werden: `php /wohnpilot/bin/reindex.php` alle 15 Minuten.

Der SQLite-Index ist nur ein Cache: Löschen von `var/index.sqlite` oder `php bin/reindex.php --full` baut ihn aus der Nextcloud neu auf.
