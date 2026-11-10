<?php

declare(strict_types=1);

/** Integration fragment: assume connected $pdo and $bloom, users(id AUTO_INCREMENT, name). */
function createUser(PDO $pdo, Bloom $bloom, string $name): string
{
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('INSERT INTO users (name) VALUES (:name)');
        $statement->execute(['name' => $name]);
        $id = $pdo->lastInsertId();

        // A false positive after rollback is acceptable. Missing a committed ID is not.
        // If this throws, do not commit the DB row under this strict policy.
        $bloom->addBeforeCommit($id);
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Create user failed: ' . $exception->getMessage());
        throw $exception;
    }
}
