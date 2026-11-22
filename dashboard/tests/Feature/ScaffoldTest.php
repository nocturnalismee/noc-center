<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScaffoldTest extends TestCase
{
    public function test_the_application_boots(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_the_php_wrapper_exists_and_is_executable(): void
    {
        $script = base_path('tools/php.sh');

        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), 'tools/php.sh must be executable.');
    }

    public function test_php_meets_the_laravel_version_requirement(): void
    {
        $this->assertTrue(
            version_compare(PHP_VERSION, '8.3.0', '>='),
            'Laravel 13 requires PHP 8.3 or newer; found '.PHP_VERSION.'.'
        );
    }

    public function test_the_sqlite_connection_is_configured(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame(5000, config('database.connections.sqlite.busy_timeout'));
        $this->assertSame('wal', config('database.connections.sqlite.journal_mode'));
        $this->assertSame(1, DB::scalar('select 1'));
    }
}
