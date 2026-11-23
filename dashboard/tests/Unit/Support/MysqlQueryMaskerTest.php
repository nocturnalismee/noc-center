<?php

namespace Tests\Unit\Support;

use App\Support\MysqlQueryMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MysqlQueryMaskerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function queries(): array
    {
        return [
            'quoted string' => ["SELECT * FROM users WHERE email = 'a@example.test'", 'SELECT * FROM users WHERE email = ?'],
            'escaped quote' => ["SELECT 'it\\'s here'", 'SELECT ?'],
            'doubled quote' => ["SELECT 'it''s here'", 'SELECT ?'],
            'integer' => ['SELECT * FROM users WHERE id = 42', 'SELECT * FROM users WHERE id = ?'],
            'negative' => ['SELECT * FROM t WHERE delta = -12', 'SELECT * FROM t WHERE delta = ?'],
            'decimal' => ['SELECT * FROM t WHERE ratio > 0.125', 'SELECT * FROM t WHERE ratio > ?'],
            'scientific' => ['SELECT * FROM t WHERE n = 1.2e-3', 'SELECT * FROM t WHERE n = ?'],
            'hex literal' => ['INSERT INTO t VALUES (0xDEADBEEF)', 'INSERT INTO t VALUES (?)'],
            'binary literal' => ['INSERT INTO t VALUES (0b1010)', 'INSERT INTO t VALUES (?)'],
            'in list' => ['SELECT * FROM t WHERE id IN (1, 2, 3)', 'SELECT * FROM t WHERE id IN (?, ?, ?)'],
            'comments and newlines' => ["SELECT *\nFROM t -- user 42\nWHERE id = 7", 'SELECT * FROM t -- user ? WHERE id = ?'],
        ];
    }

    #[DataProvider('queries')]
    public function test_it_masks_statement_literals(string $sql, string $expected): void
    {
        $this->assertSame($expected, MysqlQueryMasker::mask($sql));
    }

    public function test_it_returns_null_for_empty_input(): void
    {
        $this->assertNull(MysqlQueryMasker::mask(null));
        $this->assertNull(MysqlQueryMasker::mask("  \n "));
    }
}
