<?php

namespace Tests\Unit;

use App\Services\Collections\Kopokopo\KopokopoReference;
use Illuminate\Database\MySqlConnection;
use Tests\TestCase;

class KopokopoReferenceQueryTest extends TestCase
{
    public function test_mysql_history_lookup_has_fixed_comparison_branches_and_bound_receipt_codes(): void
    {
        $connection = new MySqlConnection(new \PDO('sqlite::memory:'), 'fixture', '', ['driver' => 'mysql']);
        $refs = array_map(fn ($n) => 'UKX'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), range(1, 3508));
        $query = $connection->table('payments')->where('platform_id', 1)->where(function ($q) use ($refs) {
            KopokopoReference::orNormalized($q, ['reference_root', 'transaction_reference', 'reference_number'], $refs);
        });
        $sql = $query->toSql();
        $this->assertSame(3, substr_count($sql, 'REGEXP_REPLACE('));
        $this->assertStringNotContainsString(' LIKE ', strtoupper($sql));
        $this->assertStringNotContainsString($refs[0], $sql);
        $this->assertSame(2 + 6 * count($refs), count($query->getBindings()));
    }

    public function test_kenya_root_semantics_preserve_only_one_positive_numeric_split(): void
    {
        $this->assertTrue(KopokopoReference::matches(" ukx fix0001/2\t", ['UKXFIX0001']));
        $this->assertTrue(KopokopoReference::matches('UKXFIX0001-19', ['UKXFIX0001']));
        foreach (['UKXFIX0001-0', 'UKXFIX0001-02', 'UKXFIX0001-NOTE', 'UKXFIX0001-1-2', 'UKXFIX0001MORE'] as $value) {
            $this->assertFalse(KopokopoReference::matches($value, ['UKXFIX0001']));
        }
    }
}
