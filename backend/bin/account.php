<?php

declare(strict_types=1);

// Manage e-mail sign-in accounts. The password is asked for, never passed
// as an argument (it would end up in the shell history).
//
//   php bin/account.php add <email> <person-id> [name]   create or replace
//   php bin/account.php password <email>                  set a new password
//   php bin/account.php list
//   php bin/account.php remove <email>
//
// <person-id> is the id of the person in _haushalt.md, e.g. "christian".

use Wohnpilot\Config;
use Wohnpilot\Services;

$backendDir = dirname(__DIR__);
date_default_timezone_set('Europe/Berlin');
require $backendDir . '/vendor/autoload.php';

$accounts = (new Services(Config::load($backendDir), $backendDir))->accounts();
[$command, $email, $person, $name] = array_pad(array_slice($argv, 1), 4, null);

function askPassword(): string
{
    $hidden = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        fwrite(STDERR, 'Passwort (mindestens ' . Wohnpilot\Auth\AccountStore::MIN_PASSWORD_LENGTH . ' Zeichen): ');
        $hidden && shell_exec('stty -echo');
        $first = rtrim((string) fgets(STDIN), "\r\n");
        fwrite(STDERR, "\nNoch einmal: ");
        $second = rtrim((string) fgets(STDIN), "\r\n");
        $hidden && shell_exec('stty echo');
        fwrite(STDERR, "\n");
        if ($first === $second) {
            return $first;
        }
        fwrite(STDERR, "Die beiden Eingaben sind verschieden.\n");
    }
    exit(1);
}

try {
    switch ($command) {
        case 'add':
            if ($email === null || $person === null) {
                throw new InvalidArgumentException('Aufruf: php bin/account.php add <email> <person-id> [name]');
            }
            $accounts->set($email, $person, askPassword(), (string) $name);
            echo "Konto $email für Person $person gespeichert.\n";
            break;
        case 'password':
            $existing = array_values(array_filter($accounts->all(), static fn (array $a): bool => $a['email'] === mb_strtolower(trim((string) $email))));
            if ($existing === []) {
                throw new InvalidArgumentException("Kein Konto für $email.");
            }
            $accounts->set($existing[0]['email'], $existing[0]['person'], askPassword(), $existing[0]['name']);
            echo "Neues Passwort für $email gespeichert.\n";
            break;
        case 'list':
            foreach ($accounts->all() as $account) {
                printf("%-40s %-15s %s (geändert %s)\n", $account['email'], $account['person'], $account['name'], date('d.m.Y', $account['changed_at']));
            }
            break;
        case 'remove':
            echo $accounts->remove((string) $email) ? "Konto $email entfernt.\n" : "Kein Konto für $email.\n";
            break;
        default:
            fwrite(STDERR, "Befehle: add <email> <person-id> [name] | password <email> | list | remove <email>\n");
            exit(1);
    }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
