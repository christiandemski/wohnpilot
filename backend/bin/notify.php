<?php

declare(strict_types=1);

// The morning push: run daily at 08:00 by cron. Sends the summary of the
// day to every device that has not had one today, so a second run in the
// same day does nothing. Usage: php bin/notify.php [--dry-run]

use Wohnpilot\Config;
use Wohnpilot\Push\DailyDigest;
use Wohnpilot\Services;

$backendDir = dirname(__DIR__);
require $backendDir . '/vendor/autoload.php';

$services = new Services(Config::load($backendDir), $backendDir);
$today = $services->clock()->today();
$services->notes()->refresh(true);
$agenda = $services->agenda()->forOverview([])['agenda'];

if (in_array('--dry-run', $argv, true)) {
    $message = (new DailyDigest())->build($agenda, $today);
    echo $message === null ? "$today: nothing to send\n" : "$today: {$message['title']}\n{$message['body']}\n";
    exit(0);
}

$push = $services->morningPush();
if ($push === null) {
    fwrite(STDERR, "Push is off: set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY (php bin/vapid.php)\n");
    exit(1);
}
$result = $push->run($agenda, $today);
if ($result['message'] === null) {
    echo "$today: nothing to send\n";
    exit(0);
}
printf("%s: \"%s\" sent %d, gone %d, failed %d, already sent %d\n", $today, $result['message']['title'], $result['sent'], $result['gone'], $result['failed'], $result['skipped']);
exit($result['failed'] > 0 ? 1 : 0);
