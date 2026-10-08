<?php

/**
 * Changes ONE on/off value of one record — the "Hide / Show", "Activate", "Verified" buttons in the admin —
 * without re-saving the whole form, and writes only that change to the audit log.
 *
 * Used inside the model classes, never from pages directly:
 *   (new RecordSwitch($this->db))->set('banners', 'banner_id', $banner_id, 'banner_is_active', 0, $admin_id, 'banner');
 *
 * $table, $id_column, $column and $only_if_sql are always fixed strings written in the calling class
 * (never user input), so they can be put into the SQL.
 */
class RecordSwitch
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Sets $column to $value. Returns true when it changed, false when it already had that value
     * (nothing is written then). Throws 404 when the record does not exist (or fails $only_if_sql).
     */
    public function set(
        string $table,
        string $id_column,
        int $record_id,
        string $column,
        string|int $value,
        int $admin_id,
        string $entity_type,             // for the audit log, e.g. 'product' → action 'product.updated'
        string $only_if_sql = '1 = 1'    // e.g. 'deleted_at IS NULL'
    ): bool {
        return $this->db->transaction(function (Database $db) use ($table, $id_column, $record_id, $column, $value, $admin_id, $entity_type, $only_if_sql) {
            $row = $db->fetchOne(
                "SELECT {$column} AS current_value FROM {$table} WHERE {$id_column} = :record_id AND {$only_if_sql} FOR UPDATE",
                ['record_id' => $record_id]
            );
            if ($row === null) {
                throw ApiException::notFound('This record was not found. It may have been deleted.');
            }
            if ((string) $row['current_value'] === (string) $value) {
                return false;
            }

            $db->execute("UPDATE {$table} SET {$column} = :value WHERE {$id_column} = :record_id", ['value' => $value, 'record_id' => $record_id]);
            (new AuditLog($db))->record('admin', $admin_id, "{$entity_type}.updated", $entity_type, $record_id,
                [$column => $row['current_value']], [$column => $value]);
            return true;
        });
    }
}
