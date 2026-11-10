<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/Jobs.php';
$loop = in_array('--loop', $argv, true);
do {
    try {
        $job = Jobs::claim();
        if ($job !== null) {
            Jobs::process($job);
            fwrite(STDOUT,'job=' . $job['id'] . ' kind=' . $job['kind'] . " processed\n");
        } elseif ($loop) sleep(2);
    } catch (Throwable $e) {
        fwrite(STDERR, 'worker error (' . get_class($e) . "): " . $e->getMessage() . "\n");
        if (!$loop) exit(1);
        sleep(5);
    }
} while ($loop);
