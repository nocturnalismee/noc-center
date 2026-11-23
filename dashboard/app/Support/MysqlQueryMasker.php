<?php

namespace App\Support;

class MysqlQueryMasker
{
    /**
     * Mask string and numeric literals in a MySQL statement.
     *
     * Query text can contain customer data, credentials, or personal
     * information. The dashboard only needs the statement shape.
     */
    public static function mask(?string $sql): ?string
    {
        if ($sql === null) {
            return null;
        }

        $sql = trim($sql);

        if ($sql === '') {
            return null;
        }

        $masked = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $sql) ?? $sql;
        $masked = preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/', '?', $masked) ?? $masked;
        $masked = preg_replace('/\b-?\d+(?:\.\d+)?(?:e[+-]?\d+)?\b/i', '?', $masked) ?? $masked;

        $masked = preg_replace('/\s+/', ' ', $masked) ?? $masked;

        return trim($masked);
    }
}
