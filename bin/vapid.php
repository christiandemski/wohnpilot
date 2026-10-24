<?php

declare(strict_types=1);

// Creates the VAPID key pair for Web Push once. Put both lines into .env;
// new keys make every device subscribe again. Usage: php bin/vapid.php

use Minishlink\WebPush\VAPID;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $keys = VAPID::createVapidKeys();
} catch (RuntimeException $e) {
    // Windows PHP needs to know its openssl.cnf to create keys.
    fwrite(STDERR, $e->getMessage() . "
On Windows set OPENSSL_CONF to <php>/extras/ssl/openssl.cnf and try again.
");
    exit(1);
}
echo "VAPID_PUBLIC_KEY={$keys['publicKey']}\n";
echo "VAPID_PRIVATE_KEY={$keys['privateKey']}\n";
