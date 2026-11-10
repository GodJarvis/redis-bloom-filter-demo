<?php

declare(strict_types=1);

/** Integration fragment: users.id is a positive, increasing integer primary key. */
function initializeFromDatabase(PDO $pdo, Bloom $bloom): int
{
    // Pause AND drain every create/restore write path before entering this function.
    $ids = (static function () use ($pdo): Generator {
        $after = '0';
        do {
            $statement = $pdo->prepare(
                'SELECT id FROM users WHERE id > :after ORDER BY id LIMIT 1000'
            );
            $statement->execute(['after' => $after]);
            $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $id) {
                $after = (string) $id;
                yield $after;
            }
        } while (count($rows) === 1000);
    })();
    return $bloom->initialize($ids);
}
