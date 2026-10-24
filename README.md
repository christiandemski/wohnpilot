# Wohnpilot

Selbst gehostete PWA für alle haushaltsrelevanten Daten eines Mietobjekts. Die Daten liegen als Markdown-Notizen in der eigenen Nextcloud. Konzept: [`docs/konzept.md`](docs/konzept.md), Designsystem: [`docs/design/README.md`](docs/design/README.md), Regeln für die Arbeit am Code: [`CLAUDE.md`](CLAUDE.md).

Stand: **Ausbaustufe 3** – Haus im Griff: Wartung, Termine und Müll, Mängel, Geräte (auf Stufe 1: Zähler, Stufe 2: Fristen und Verträge).

## Aufbau

| Ordner | Inhalt |
| --- | --- |
| `backend/` | PHP 8.3+ mit Slim 4: JSON-API unter `/api`, Login, Nextcloud-Anbindung, Berechnungen, SQLite-Index |
| `backend/src/Note/` | Verlustfreier Parser für Frontmatter und Markdown-Tabellen |
| `backend/src/Storage/` | `StorageAdapter` mit Nextcloud-Notes- und Dateisystem-Adapter, Fotos per WebDAV (`AttachmentStore`) |
| `backend/src/Domain/` | Verbrauch, Plausibilität, Fälligkeit, Kosten-Hochrechnung, Vertragslaufzeit, Kündigungs- und Zustimmungsfristen, Wartung mit Saison, Mängel und Kleinreparaturen, Garantie – nur berechnet, nie gespeichert |
| `backend/src/Calendar/` | ICS-Feed, geheime Kalender-Links, Abfuhrkalender lesen und abrufen |
| `backend/src/Push/` | Web Push: Abos, tägliche Zusammenfassung, Versand |
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
cd frontend && npm test            # Zahlenformat, Rollenzähler, Plausibilität, Frist-, Vertrags- und Haustexte
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

### Bereitstellung über Git (Plesk)

Bei jedem Push auf `main` testet und baut eine GitHub Action (`.github/workflows/deploy.yml`) den Inhalt von `httpdocs` und legt ihn in den Branch **`deploy`**: `public/` mit gebauter App, `src/`, `bin/`, `vendor/` ohne Entwicklungspakete, `composer.*`, `var/.htaccess`. Schlagen Tests fehl, bleibt `deploy` unverändert.

In Plesk (Git bei `wohnpilot.codekorb.de`):

- Repository `https://github.com/christiandemski/wohnpilot.git`, **Branch `deploy`**
- **Serverpfad `/wohnpilot.codekorb.de/httpdocs`** – niemals `httpdocs/public`, sonst liegen die Dateien im Web
- Bereitstellung automatisch; Document Root bleibt `httpdocs/public`

`.env` und die Daten in `var/` liegen nur auf dem Server und sind nicht im Branch; ein Pull lässt sie stehen. `main` selbst ist nicht lauffähig (ohne `vendor/` und ohne gebaute App) und gehört nie auf den Server.

### Bereitstellung per Upload-Paket

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
7. **Push einrichten** (einmalig per SSH): `php bin/vapid.php` erzeugt zwei Zeilen `VAPID_PUBLIC_KEY=…` und `VAPID_PRIVATE_KEY=…` für die `.env`; dazu `VAPID_SUBJECT=mailto:<deine-email>`. Neue Schlüssel bedeuten, dass jedes Gerät die Mitteilungen neu einschalten muss.
8. **Cronjob für die Morgen-Mitteilung** (Plesk → Geplante Aufgaben, täglich 08:00): `php /var/www/vhosts/hosting226222.ae9bd.netcup.net/wohnpilot.codekorb.de/httpdocs/bin/notify.php`. Ein zweiter Lauf am selben Tag sendet nichts doppelt; `--dry-run` zeigt nur, was rausginge.
9. **Optional Cronjob** (alle 15 Minuten), damit Änderungen aus der Notes-App auch ohne Aufruf eingelesen werden: `php /var/www/vhosts/hosting226222.ae9bd.netcup.net/wohnpilot.codekorb.de/httpdocs/bin/reindex.php`

Den Kalender-Feed unter `/api/calendar/…` holen Kalender-Apps ohne Anmeldung ab. Schalte deshalb keinen BearID-Guard (`protect.php`) vor die ganze Subdomain; Wohnpilot prüft die Anmeldung selbst.

Bei späteren Updates nur `public/`, `src/`, `bin/`, `vendor/` und `composer.*` ersetzen; `.env` und `var/` bleiben. Ins Backup gehören `var/accounts.sqlite` (Notzugang) und `var/settings.sqlite` (Kalender-Links, Push-Abos). `var/cache/` (Abfuhrkalender) und `var/index.sqlite` sind nur Cache. Fotos liegen in der Nextcloud unter `Wohnpilot/anhaenge/`.

Der SQLite-Index ist nur ein Cache: Löschen von `var/index.sqlite` oder `php bin/reindex.php --full` baut ihn aus der Nextcloud neu auf.
