<?php

/**
 * Database helper (PDO). Every query uses prepared statements, so user input can never change the SQL.
 *
 * How to use it:
 *   $db   = Database::instance();
 *   $user = $db->fetchOne('SELECT * FROM users WHERE user_phone = :user_phone', ['user_phone' => $phone]);
 *   $id   = $db->insert('INSERT INTO users (user_phone) VALUES (:user_phone)', ['user_phone' => $phone]);
 *
 *   // Several queries that must succeed or fail together (orders, payments, stock):
 *   $db->transaction(function (Database $db) {
 *       // ... all saved together, or nothing is saved if an error happens
 *   });
 */
class Database
{
    // The one shared Database object for this request (see instance())
    private static ?Database $instance = null;

    // The real PDO connection — opened only when the first query runs
    private ?PDO $pdo = null;

    // How many transaction() calls are currently open (used to allow one inside another)
    private int $transaction_depth = 0;

    /** Returns the shared Database object, so the whole request uses one connection. */
    public static function instance(): Database
    {
        // "??=" means: create it only if it does not exist yet
        return self::$instance ??= new Database();
    }

    /** Opens the connection the first time it is needed, using the settings in .env. */
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

            // All dates are saved in UTC; they are converted to Tanzania time only when displayed
            $this->pdo->exec("SET time_zone = '+00:00'");
        }

        return $this->pdo;
    }

    /** Prepares and runs a query with its values. Used by all the methods below. */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** One row, or null when nothing matches. */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** All rows (an empty array when nothing matches). */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** A single value, e.g. the result of SELECT COUNT(*). */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Runs an INSERT and returns the id of the new row. */
    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo()->lastInsertId();
    }

    /** Runs an UPDATE or DELETE and returns how many rows changed. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Runs $work inside a transaction:
     * - if $work finishes, everything is saved (commit)
     * - if $work throws an error, everything is undone (rollback) and the error continues upwards
     *
     * If a transaction is already open (one class method calling another), the inner call
     * simply joins the outer one, so everything is still saved or undone together.
     */
    public function transaction(callable $work): mixed
    {
        // Already inside a transaction: just run the work as part of it
        if ($this->transaction_depth > 0) {
            $this->transaction_depth++;
            try {
                return $work($this);
            } finally {
                $this->transaction_depth--;
            }
        }

        // Start a new transaction
        $this->pdo()->beginTransaction();
        $this->transaction_depth = 1;

        try {
            $result = $work($this);
            $this->pdo()->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $exception;
        } finally {
            $this->transaction_depth = 0;
        }
    }
}
