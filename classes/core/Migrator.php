<?php

/**
 * Creates and updates the database from the files in database/migrations and database/seeds.
 *
 * - Migrations (001_core.sql, 002_catalog.sql …) run once each, in order. Every applied file
 *   is recorded in the `migrations` table, so running again only applies the new ones.
 * - Seeds (database/seeds/*.php) fill in starting data. They are safe to run many times:
 *   each seed only adds what is missing.
 *
 * Used by database/migrate.php (command line only).
 */
class Migrator
{
    private string $migrations_folder;
    private string $seeds_folder;

    public function __construct(private Database $db)
    {
        $this->migrations_folder = BASE_PATH . '/database/migrations';
        $this->seeds_folder      = BASE_PATH . '/database/seeds';
    }

    /** Applies every migration file that has not run yet. Returns the names of the files applied. */
    public function runPendingMigrations(): array
    {
        $this->createMigrationsTable();

        $applied_files = [];
        foreach ($this->pendingMigrationFiles() as $file_name) {
            $sql = file_get_contents("{$this->migrations_folder}/{$file_name}");

            // MySQL cannot undo CREATE TABLE with a rollback, so statements run one by one;
            // if one fails, the error stops the run and the file is not recorded as applied.
            foreach ($this->splitStatements($sql) as $statement_sql) {
                $this->db->pdo()->exec($statement_sql);
            }

            $this->db->insert(
                'INSERT INTO migrations (migration_file) VALUES (:migration_file)',
                ['migration_file' => $file_name]
            );
            $applied_files[] = $file_name;
        }

        return $applied_files;
    }

    /** Runs every seed file in name order. Returns the names of the seeds that ran. */
    public function runSeeds(): array
    {
        $seed_files = glob("{$this->seeds_folder}/*.php");
        sort($seed_files);

        foreach ($seed_files as $seed_file) {
            // Each seed file returns a function that receives the database
            $seed = require $seed_file;
            $seed($this->db);
        }

        return array_map('basename', $seed_files);
    }

    /** Every migration file with true (applied) or false (pending). */
    public function status(): array
    {
        $this->createMigrationsTable();
        $applied = $this->appliedMigrationFiles();

        $status = [];
        foreach ($this->allMigrationFiles() as $file_name) {
            $status[$file_name] = in_array($file_name, $applied, true);
        }
        return $status;
    }

    private function createMigrationsTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                migration_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration_file  VARCHAR(191) NOT NULL,
                applied_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration_id),
                UNIQUE KEY uq_migration_file (migration_file)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function allMigrationFiles(): array
    {
        $file_names = array_map('basename', glob("{$this->migrations_folder}/*.sql"));
        sort($file_names); // 001_…, 002_…, 003_… — the number decides the order
        return $file_names;
    }

    private function appliedMigrationFiles(): array
    {
        return array_column($this->db->fetchAll('SELECT migration_file FROM migrations'), 'migration_file');
    }

    private function pendingMigrationFiles(): array
    {
        return array_values(array_diff($this->allMigrationFiles(), $this->appliedMigrationFiles()));
    }

    /**
     * Splits a .sql file into single statements: removes "-- comment" lines,
     * then cuts at every ";" that ends a line.
     */
    private function splitStatements(string $sql): array
    {
        $without_comments = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements       = preg_split('/;\s*(\r?\n|$)/', $without_comments);

        return array_values(array_filter(array_map('trim', $statements)));
    }
}
