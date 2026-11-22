<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqlitePragmaTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $this->databasePath = $directory.'/wal.sqlite';
        $this->removeDatabaseFiles();

        touch($this->databasePath);

        config([
            'database.connections.wal_test' => array_merge(
                config('database.connections.sqlite'),
                ['database' => $this->databasePath],
            ),
        ]);

        DB::purge('wal_test');
    }

    protected function tearDown(): void
    {
        DB::disconnect('wal_test');
        DB::purge('wal_test');
        $this->removeDatabaseFiles();

        parent::tearDown();
    }

    public function test_the_file_backed_connection_uses_wal_and_enforced_foreign_keys(): void
    {
        $exitCode = Artisan::call('migrate', [
            '--database' => 'wal_test',
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());

        $connection = DB::connection('wal_test');

        $this->assertSame('wal', strtolower((string) $connection->selectOne('PRAGMA journal_mode')->journal_mode));
        $this->assertSame(5000, (int) $connection->selectOne('PRAGMA busy_timeout')->timeout);
        $this->assertSame(1, (int) $connection->selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('servers'));
    }

    private function removeDatabaseFiles(): void
    {
        foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
