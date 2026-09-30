<?php

/**
 * Unique web-address names ("slugs") for categories, sellers and products.
 *
 *   Slug::unique($db, 'Gold Plated Bangles', 'products', 'product_slug', 'product_id');
 *   // → "gold-plated-bangles", or "gold-plated-bangles-2" if that one is taken
 */
class Slug
{
    /**
     * $table and the column names always come from our code, never from user input.
     * $ignore_id: when editing, the row's own slug doesn't count as "taken".
     */
    public static function unique(Database $db, string $text, string $table, string $slug_column, string $id_column, ?int $ignore_id = null): string
    {
        $base_slug = slugify($text);
        $slug      = $base_slug;
        $number    = 1;

        while ($db->fetchValue(
            "SELECT 1 FROM {$table} WHERE {$slug_column} = :slug AND {$id_column} <> :ignore_id",
            ['slug' => $slug, 'ignore_id' => $ignore_id ?? 0]
        )) {
            $slug = $base_slug . '-' . ++$number;
        }

        return $slug;
    }
}
