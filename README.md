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

Ziel: Subdomain `wohnpilot.codekorb.de`, Ordner im Webspace `wohnpilot.codekorb.de/httpdocs/`, **Document Root `wohnpilot.codekorb.de/httpdocs/public`**. So sind `.env`, `var/` und `vendor/` eine Ebene über dem Document Root und nicht aus dem Web erreichbar. Der Webspace liegt unter `/var/www/vhosts/hosting226222.ae9bd.netcup.net/`.

```
/var/www/vhosts/hosting226222.ae9bd.netcup.net/
  bearid-guard/                 public.key, guard.php (von BearID)
  wohnpilot.codekorb.de/
    httpdocs/                   Inhalt von backend/
      .env                      Zugangsdaten (nie ins Repo)
      bin/  src/  vendor/  var/  composer.json
      public/                   ← Document Root: index.php, .htaccess, gebaute App
```

1. **Paket bauen:** `php tools/build-deploy.php` baut das Frontend, stellt das Backend ohne Tests und Entwicklungspakete zusammen und legt `dist/wohnpilot-deploy-<datum>-<commit>.zip` an. Darin liegen auch `LIESMICH.txt` und `.env.server` (vorausgefüllt, ohne Passwort).
2. **Hochladen:** den *Inhalt* des ZIP nach `wohnpilot.codekorb.de/httpdocs/`, mit den versteckten Dateien. `var/` muss für PHP beschreibbar sein.
3. **`.env`:** `.env.server` in `.env` umbenennen und das Nextcloud-App-Passwort eintragen. Vorausgefüllt sind:
   - `APP_ENV=prod`, `APP_URL=https://wohnpilot.codekorb.de`, `DEV_LOGIN_USER` leer
   - Nextcloud-URL und -Benutzer (das App-Passwort trägst du selbst ein)
   - `BEARID_PUBLIC_KEY_FILE=/var/www/vhosts/hosting226222.ae9bd.netcup.net/bearid-guard/public.key`
   - `WOHNPILOT_USERS=` deine E-Mail-Adresse (darf den Haushalt anlegen)
4. **Plesk, Subdomain `wohnpilot.codekorb.de`:**
   - Document Root `httpdocs/public`, Let's-Encrypt-Zertifikat
   - PHP 8.3 oder neuer, `pdo_sqlite` und `sodium` aktiv
   - `open_basedir` auf `{WEBSPACEROOT}{/}{:}{TMP}{/}`, damit PHP an `bearid-guard/public.key` kommt
5. **BearID** (`id.codekorb.de` → Tools → Tool anlegen): Name `Wohnpilot`, Kürzel `wohnpilot`, Adresse `https://wohnpilot.codekorb.de`, Symbol `home`. Andere Personen gibst du unter Benutzer frei.
6. **Notzugang** per SSH: `cd /var/www/vhosts/hosting226222.ae9bd.netcup.net/wohnpilot.codekorb.de/httpdocs && php bin/account.php add <deine-email> christian`, Passwort mit mindestens 12 Zeichen. `var/accounts.sqlite` gehört ins Backup.
7. **Optional Cronjob** (alle 15 Minuten), damit Änderungen aus der Notes-App auch ohne Aufruf eingelesen werden: `php /var/www/vhosts/hosting226222.ae9bd.netcup.net/wohnpilot.codekorb.de/httpdocs/bin/reindex.php`

Bei späteren Updates nur `public/`, `src/`, `bin/`, `vendor/` und `composer.*` ersetzen; `.env` und `var/` bleiben.

Der SQLite-Index ist nur ein Cache: Löschen von `var/index.sqlite` oder `php bin/reindex.php --full` baut ihn aus der Nextcloud neu auf.
