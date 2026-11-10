<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bloom.php';

if (getenv('BLOOM_TEST') !== '1') {
    fwrite(STDERR, "Run via bash tests/run.sh in its isolated Redis project.\n");
    exit(1);
}

const BITS = 'demo:bloom:{users}:bits';
const META = 'demo:bloom:{users}:meta';

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        verify(true, $message);
        return;
    }
    verify(false, $message);
}

try {
    $redis = new Redis();
    $redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), 2.0);
    $redis->setOption(Redis::OPT_READ_TIMEOUT, 5.0);
    $bloom = new Bloom($redis);
    $ids = ['1001', '1002', '550e8400-e29b-41d4-a716-446655440000'];
    $phase = $argv[1] ?? '';

    if ($phase === 'prepare') {
        verify($redis->config('GET', 'appendonly')['appendonly'] === 'yes', 'AOF persistence is enabled');
        verify($bloom->check('1001') === Bloom::UNKNOWN, 'uninitialized lookup is UNKNOWN');
        rejects(fn () => $bloom->addBeforeCommit('1001'), 'writes rejected before initialization');
        verify($bloom->initialize($ids) === 3, 'full input publishes READY');
        foreach ($ids as $id) {
            verify($bloom->check($id) === Bloom::MAYBE, 'initialized item ' . $id);
        }
        verify($bloom->check('definitely-not-in-db') === Bloom::ABSENT, 'fixture missing item is ABSENT');
        $bloom->addBeforeCommit('1003');
        $bloom->addBeforeCommit('1003');
        verify($bloom->check('1003') === Bloom::MAYBE, 'incremental and repeated add are safe');
        rejects(fn () => $bloom->addBeforeCommit(''), 'empty write ID rejected');

        $many = array_map(static fn (int $i): string => '用户-' . $i, range(1, 300));
        $bloom->initialize([...$many, ...$ids, $ids[0]]);
        verify(count(array_filter($many, fn (string $id): bool => $bloom->check($id) !== Bloom::MAYBE)) === 0,
            '300 UTF-8 IDs have no false negatives');

        foreach (['m' => '999', 'k' => '1', 'algorithm' => 'wrong', 'run_id' => 'old', 'state' => 'building'] as $field => $bad) {
            $original = $redis->hGet(META, $field);
            $redis->hSet(META, $field, $bad);
            verify($bloom->check('1001') === Bloom::UNKNOWN, 'invalid ' . $field . ' is UNKNOWN');
            rejects(fn () => $bloom->addBeforeCommit('new'), 'invalid ' . $field . ' rejects writes');
            $redis->hSet(META, $field, $original);
        }

        $brokenInput = (static function (): Generator {
            yield '1001';
            throw new RuntimeException('simulated database scan failure');
        })();
        rejects(fn () => $bloom->initialize($brokenInput), 'source scan failure is observable');
        verify($redis->hGet(META, 'state') === 'building', 'failed scan never publishes READY');
        verify($bloom->check('1001') === Bloom::UNKNOWN, 'partially built filter is UNKNOWN');

        // A newer builder supersedes the old token; the old builder must fail.
        $superseded = (static function () use ($bloom): Generator {
            $bloom->initialize(['new-build']);
            yield 'old-build';
        })();
        rejects(fn () => $bloom->initialize($superseded), 'superseded build cannot load');
        verify($bloom->check('new-build') === Bloom::MAYBE, 'newer build remains usable');
        $supersededPublish = (static function () use ($bloom): Generator {
            $bloom->initialize(['new-published-build']);
            yield from [];
        })();
        rejects(fn () => $bloom->initialize($supersededPublish), 'superseded build cannot publish');
        verify($bloom->check('new-published-build') === Bloom::MAYBE, 'newer published build remains usable');

        $bloom->initialize($ids);
        $before = hash('sha256', $redis->get(BITS));
        $lua = file_get_contents(dirname(__DIR__) . '/bloom.lua');
        try {
            $result = $redis->eval($lua, [BITS, META, 'add', '10000000', '7', 'sha256-double-v1', '',
                '1', '2', '3', '4', '5', '6', '10000000'], 2);
            verify($result === false, 'Lua rejects an out-of-range position');
        } catch (RedisException) {
            verify(true, 'Lua rejects an out-of-range position');
        }
        verify(hash('sha256', $redis->get(BITS)) === $before, 'invalid Lua input does not partially set bits');

        $redis->del(BITS);
        verify($bloom->check('1001') === Bloom::UNKNOWN, 'missing bitmap is UNKNOWN');
        $redis->set(BITS, 'short');
        verify($bloom->check('1001') === Bloom::UNKNOWN, 'truncated bitmap is UNKNOWN');
        $redis->del(BITS);
        $redis->hSet(BITS, 'bad', 'type');
        verify($bloom->check('1001') === Bloom::UNKNOWN, 'wrong bitmap type is UNKNOWN');
        $bloom->initialize($ids);
        $redis->del(META);
        verify($bloom->check('1001') === Bloom::UNKNOWN, 'missing metadata is UNKNOWN');
        $redis->set(META, 'bad-type');
        verify($bloom->check('1001') === Bloom::UNKNOWN, 'wrong metadata type is UNKNOWN');
        $redis->del(META);
        verify($bloom->initialize([]) === 0, 'complete empty dataset can be published');
        verify($bloom->check('1001') === Bloom::ABSENT, 'empty dataset lookup is ABSENT');
        $bloom->initialize($ids);
        $bloom->addBeforeCommit('1003');
    } elseif ($phase === 'checkpoint') {
        $redis->set('test:bitmap-sha256', hash('sha256', $redis->get(BITS)));
        $redis->set('test:run-id', $redis->info('server')['run_id']);
        verify($redis->hGet(META, 'state') === 'ready', 'metadata is READY before restart');
    } elseif ($phase === 'recover') {
        verify($redis->get('test:run-id') !== $redis->info('server')['run_id'], 'Redis process identity changed');
        verify($redis->get('test:bitmap-sha256') === hash('sha256', $redis->get(BITS)), 'AOF reload preserves exact bitmap');
        verify($redis->hGet(META, 'state') === 'ready', 'AOF restored old READY metadata');
        verify($bloom->check('1001') === Bloom::UNKNOWN, 'restart forces UNKNOWN despite restored READY');
        rejects(fn () => $bloom->addBeforeCommit('1004'), 'writes rejected until rebuild after restart');
        $bloom->initialize([...$ids, '1003']);
        foreach ([...$ids, '1003'] as $id) {
            verify($bloom->check($id) === Bloom::MAYBE, 'rebuilt dataset retains ' . $id);
        }
        verify($bloom->check('definitely-not-in-db') === Bloom::ABSENT, 'rebuild restores negative lookup');
        // Closing the PHP socket alone permits automatic reconnect to a healthy server.
        // Stop the actual isolated Redis server to test an unavailable connection.
        try {
            $redis->rawCommand('SHUTDOWN', 'NOSAVE');
        } catch (RedisException) {
            // A successful shutdown can close the connection without a reply.
        }
        verify($bloom->check('1001') === Bloom::UNKNOWN, 'stopped Redis server is UNKNOWN');
    } else {
        throw new InvalidArgumentException('Unknown test phase');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
