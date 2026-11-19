<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/whatsapp_Jobs.php';
require dirname(__DIR__) . '/src/whatsapp_Fcm.php';
$loop = in_array('--loop', $argv, true);
do {
    try {
        $job = WhatsAppFcm::claim();
        $isPush = $job !== null;
        if ($job === null) $job = Jobs::claim();
        if ($job !== null) {
            if ($isPush) WhatsAppFcm::process($job);
            else Jobs::process($job);
            fwrite(STDOUT,'job=' . $job['id'] . ' kind=' . ($isPush?'push':$job['kind']) . " processed\n");
        } elseif ($loop) sleep(2);
    } catch (Throwable $e) {
        fwrite(STDERR, 'worker error (' . get_class($e) . "): " . $e->getMessage() . "\n");
        if (!$loop) exit(1);
        sleep(5);
    }
} while ($loop);
