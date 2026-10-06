<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * Matches a numeric id inside legacy article_meta.category_ids stored as a
 * flattened JSON-ish list, e.g. `[1, 2, 3]` or `1,2,3`.
 *
 * FIND_IN_SET is MySQL/MariaDB-specific. Do not replace with substring LIKE
 * (false positives) or JSON_CONTAINS until storage is proven JSON on all rows.
 */
final class LegacyFlatJsonIdListSql
{
    public static function contains(string $idExpression = '?'): string
    {
        return sprintf(
            'FIND_IN_SET(%s, REPLACE(REPLACE(REPLACE(`meta_value`, " ", ""), "[", ""), "]", "")) > 0',
            $idExpression,
        );
    }
}
