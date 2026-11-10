<?php

declare(strict_types=1);

/** Teaching example: 64-bit PHP, ext-redis; no application framework needed. */
final class Bloom
{
    public const ABSENT = 0;
    public const MAYBE = 1;
    public const UNKNOWN = 2;
    private const M = 10000000;
    private const K = 7;
    private const ALGORITHM = 'sha256-double-v1';
    private const BITMAP = 'demo:bloom:{users}:bits';
    private const META = 'demo:bloom:{users}:meta';
    private string $lua;

    public function __construct(private Redis $redis)
    {
        if (PHP_INT_SIZE < 8) {
            throw new RuntimeException('64-bit PHP is required');
        }
        $source = file_get_contents(__DIR__ . '/bloom.lua');
        if ($source === false) {
            throw new RuntimeException('Cannot read bloom.lua');
        }
        $this->lua = $source;
    }

    /** Complete ID set, while ALL creation/restore paths are paused. */
    public function initialize(iterable $ids): int
    {
        $token = bin2hex(random_bytes(16));
        $this->execute('init', [], $token);
        $count = 0;
        foreach ($ids as $id) {
            $this->execute('load', $this->positions((string) $id), $token);
            ++$count;
        }
        // On any exception this is not called: queries remain UNKNOWN.
        $this->execute('publish', [], $token);
        return $count;
    }

    /** Run after allocating/inserting the ID, BEFORE committing its DB row. */
    public function addBeforeCommit(string $id): void
    {
        $this->execute('add', $this->positions($id));
    }

    /** 0=absent, 1=maybe, 2=unavailable; never turn an outage into ABSENT. */
    public function check(string $id): int
    {
        try {
            return $this->execute('check', $this->positions($id));
        } catch (Throwable $exception) {
            error_log('Bloom lookup failed: ' . $exception->getMessage());
            return self::UNKNOWN;
        }
    }

    private function positions(string $id): array
    {
        if ($id === '') {
            throw new InvalidArgumentException('ID must not be empty');
        }
        $hash = unpack('Nfirst/Nsecond', substr(hash('sha256', $id, true), 0, 8));
        $first = $hash['first'];
        $step = $hash['second'] | 1;
        $positions = [];
        for ($i = 0; $i < self::K; ++$i) {
            $positions[] = (string) (($first + $i * $step) % self::M);
        }
        return $positions;
    }

    private function execute(string $action, array $positions, string $token = ''): int
    {
        $reply = $this->redis->eval($this->lua, [
            self::BITMAP, self::META,
            $action, (string) self::M, (string) self::K,
            self::ALGORITHM, $token, ...$positions,
        ], 2);
        if (!is_int($reply) || !in_array($reply, [0, 1, 2], true)) {
            throw new RuntimeException('Bloom script failed');
        }
        return $reply;
    }
}
