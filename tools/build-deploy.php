<?php

declare(strict_types=1);

/**
 * Builds the upload package for Netcup: the contents of
 * wohnpilot.codekorb.de/httpdocs/ (document root httpdocs/public).
 *
 *   php tools/build-deploy.php
 *
 * Steps: build the frontend, copy backend/ without tests, var/ and .env,
 * install vendor/ without dev packages, add a .env template for the server
 * and zip everything into dist/. Secrets never go into the package.
 */

$root = dirname(__DIR__);
$backend = "$root/backend";
$dist = "$root/dist";
$stage = "$dist/stage";
$composer = getenv('COMPOSER_PHAR') ?: (PHP_OS_FAMILY === 'Windows' ? getenv('LOCALAPPDATA') . '/Programs/composer/composer.phar' : trim((string) shell_exec('command -v composer')));

function run(string $command, string $cwd): void
{
    echo "› $command\n";
    $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    if (!is_resource($process) || proc_close($process) !== 0) {
        fwrite(STDERR, "Fehlgeschlagen: $command\n");
        exit(1);
    }
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function copyTree(string $from, string $to, callable $skip): void
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));
        if ($skip($relative)) {
            continue;
        }
        $target = "$to/$relative";
        if ($item->isDir()) {
            @mkdir($target, 0775, true);
        } else {
            @mkdir(dirname($target), 0775, true);
            copy($item->getPathname(), $target);
        }
    }
}

/** Non-secret values from the local .env, so the template is mostly filled in. */
function localEnv(string $file): array
{
    $values = [];
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
            $values[$m[1]] = $m[2];
        }
    }
    return $values;
}

// 1. Frontend into backend/public
run('npm run build', "$root/frontend");
foreach (['index.html', 'sw.js', 'manifest.webmanifest', 'assets'] as $built) {
    if (!file_exists("$backend/public/$built")) {
        fwrite(STDERR, "Frontend-Build unvollständig: public/$built fehlt\n");
        exit(1);
    }
}

// 2. Backend without development parts
removeTree($stage);
mkdir($stage, 0775, true);
$excluded = ['tests', 'var', 'vendor', '.phpunit.cache', 'phpunit.xml', '.env'];
copyTree($backend, $stage, static function (string $path) use ($excluded): bool {
    $first = explode('/', $path)[0];
    return in_array($first, $excluded, true) || str_ends_with($path, '.sqlite');
});

// 3. Production dependencies only
run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composer) . ' install --no-dev --optimize-autoloader --no-interaction --no-progress', $stage);

// 4. Writable var/ (outside the document root anyway) and the .env template
mkdir("$stage/var", 0775);
file_put_contents("$stage/var/.htaccess", "Require all denied\n");
$local = localEnv("$root/.env");
$template = [
    'APP_ENV' => 'prod',
    'APP_URL' => 'https://wohnpilot.codekorb.de',
    'STORAGE' => 'nextcloud',
    'NEXTCLOUD_URL' => $local['NEXTCLOUD_URL'] ?? 'https://cloud.codekorb.de',
    'NEXTCLOUD_USER' => $local['NEXTCLOUD_USER'] ?? '',
    'NEXTCLOUD_APP_PASSWORD' => '',
    'NEXTCLOUD_FOLDER' => $local['NEXTCLOUD_FOLDER'] ?? 'Wohnpilot',
    'INDEX_FILE' => 'var/index.sqlite',
    'SYNC_MAX_AGE' => '30',
    'BEARID_ISSUER' => 'https://id.codekorb.de',
    'BEARID_PUBLIC_KEY_FILE' => '/var/www/vhosts/hosting226222.ae9bd.netcup.net/bearid-guard/public.key',
    'BEARID_APP' => 'wohnpilot',
    'ACCOUNTS_FILE' => 'var/accounts.sqlite',
    'WOHNPILOT_USERS' => $local['WOHNPILOT_USERS'] ?? '',
    'DEV_LOGIN_USER' => '',
];
$env = "# Wohnpilot auf wohnpilot.codekorb.de. Als .env neben composer.json speichern.\n"
    . "# NEXTCLOUD_APP_PASSWORD hier auf dem Server eintragen – es ist absichtlich nicht im Paket.\n";
foreach ($template as $key => $value) {
    $env .= "$key=$value\n";
}
file_put_contents("$stage/.env.server", $env);

file_put_contents("$stage/LIESMICH.txt", <<<'TXT'
Wohnpilot – Upload-Paket

Ziel:          wohnpilot.codekorb.de/httpdocs/   (alles aus diesem Paket)
Document Root: wohnpilot.codekorb.de/httpdocs/public

Erstinstallation
1. Alles hochladen, auch die versteckten Dateien (.env.server, public/.htaccess, var/.htaccess).
2. .env.server in .env umbenennen und NEXTCLOUD_APP_PASSWORD eintragen.
3. Plesk: PHP 8.3+, pdo_sqlite und sodium aktiv, Let's-Encrypt-Zertifikat,
   open_basedir auf {WEBSPACEROOT}{/}{:}{TMP}{/}, var/ beschreibbar.
4. BearID → Tools → Tool anlegen: Kürzel wohnpilot, Adresse https://wohnpilot.codekorb.de
5. Notzugang per SSH:  php bin/account.php add <deine-email> christian

Update
Nur public/, src/, bin/, vendor/ und composer.* ersetzen. .env und var/ bleiben stehen.
var/accounts.sqlite gehört ins Backup.
TXT);

// 5. Zip
$commit = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD'));
$dirty = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' status --porcelain -- backend frontend')) !== '';
$zipFile = sprintf('%s/wohnpilot-deploy-%s-%s%s.zip', $dist, date('Y-m-d'), $commit ?: 'nogit', $dirty ? '-geaendert' : '');
@unlink($zipFile);
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Kann $zipFile nicht anlegen\n");
    exit(1);
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$count = 0;
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($stage) + 1));
    if ($file->isDir()) {
        $zip->addEmptyDir($relative);
    } else {
        $zip->addFile($file->getPathname(), $relative);
        $count++;
    }
}
$zip->close();
removeTree($stage);

printf("\nFertig: %s (%d Dateien, %.1f MB)\n", $zipFile, $count, filesize($zipFile) / 1048576);
