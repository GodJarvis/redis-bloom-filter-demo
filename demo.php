<?php

declare(strict_types=1);

require __DIR__ . '/Bloom.php';

try {
    $command = $argv[1] ?? 'help';
    $usage = 'Usage: php demo.php init < ids.txt | add ID | check ID';
    if (in_array($command, ['help', '--help', '-h'], true)) {
        echo $usage . PHP_EOL;
        exit(0);
    }
    if (($command === 'init' && $argc !== 2)
        || (in_array($command, ['add', 'check'], true) && ($argc !== 3 || $argv[2] === ''))
        || !in_array($command, ['init', 'add', 'check'], true)) {
        throw new InvalidArgumentException($usage);
    }
    $redis = new Redis();
    $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6389), 2.0);
    $password = getenv('REDIS_PASSWORD');
    if ($password !== false && $password !== '') {
        $username = getenv('REDIS_USERNAME');
        $redis->auth($username ? [$username, $password] : $password);
    }
    $redis->setOption(Redis::OPT_READ_TIMEOUT, 5.0);
    $bloom = new Bloom($redis);

    if ($command === 'init') {
        $ids = (static function (): Generator {
            while (($line = fgets(STDIN)) !== false) {
                $id = rtrim($line, "\r\n");
                if ($id !== '') {
                    yield $id;
                }
            }
            if (!feof(STDIN)) {
                throw new RuntimeException('Cannot read the complete ID input');
            }
        })();
        echo 'READY, input rows: ' . $bloom->initialize($ids) . PHP_EOL;
    } elseif ($command === 'add') {
        $bloom->addBeforeCommit($argv[2] ?? '');
        echo "ADDED\n";
    } elseif ($command === 'check') {
        $result = $bloom->check($argv[2] ?? '');
        echo [Bloom::ABSENT => 'ABSENT', Bloom::MAYBE => 'MAYBE', Bloom::UNKNOWN => 'UNKNOWN'][$result] . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
