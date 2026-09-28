<?php

/**
 * Small helper around PDO. Every query uses prepared statements.
 *
 * Usage:
 *   $db   = Database::instance();
 *   $user = $db->fetchOne('SELECT * FROM users WHERE phone = :phone', ['phone' => $phone]);
 *   $id   = $db->insert('INSERT INTO users (phone) VALUES (:phone)', ['phone' => $phone]);
 *
 *   $db->transaction(function (Database $db) {
 *       // several queries — all saved together, or none if an exception is thrown
 *   });
 */
class Database
{
    private static ?Database $instance = null;

    private ?PDO $pdo = null;
    private int $transactionDepth = 0;

    /** One shared connection per request. */
    public static function instance(): Database
    {
        return self::$instance ??= new Database();
    }

    /** The PDO connection, opened the first time it is needed. */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::get('DB_PORT', '3306'),
                Env::required('DB_DATABASE')
            );

            $this->pdo = new PDO($dsn, Env::get('DB_USERNAME', 'root'), (string) Env::get('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // SQL errors throw exceptions
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // rows come back as ['column' => value]
                PDO::ATTR_EMULATE_PREPARES   => false,                  // real prepared statements
                PDO::ATTR_STRINGIFY_FETCHES  => false,                  // numbers stay numbers
            ]);

            // The database stores all dates in UTC
            $this->pdo->exec("SET time_zone = '+00:00'");
        }

        return $this->pdo;
    }

    /** Runs a query and returns the statement. */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /** First row, or null when nothing matches. */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** All rows (empty array when nothing matches). */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** The first column of the first row, e.g. for COUNT(*). */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** INSERT and return the new row id. */
    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo()->lastInsertId();
    }

    /** UPDATE / DELETE and return the number of affected rows. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Runs $work inside a transaction: commit if it finishes, rollback if it throws.
     * Nested calls join the outer transaction, so services can safely call each other.
     */
    public function transaction(callable $work): mixed
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;
            try {
                return $work($this);
            } finally {
                $this->transactionDepth--;
            }
        }

        $this->pdo()->beginTransaction();
        $this->transactionDepth = 1;

        try {
            $result = $work($this);
            $this->pdo()->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        } finally {
            $this->transactionDepth = 0;
        }
    }
}
