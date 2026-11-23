<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class SettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = $this->app->make(SettingsRepository::class);
    }

    public function test_an_unknown_key_returns_the_supplied_default(): void
    {
        $this->assertSame('fallback', $this->settings->get('alerts.unknown.key', 'fallback'));
        $this->assertNull($this->settings->get('alerts.unknown.key'));
    }

    public function test_config_defaults_are_returned_when_no_override_exists(): void
    {
        $this->assertSame(6, $this->settings->get('alerts.reminder_hours'));
        $this->assertSame(90, $this->settings->get('alerts.rules.cpu_high.warning'));
        $this->assertSame(95, $this->settings->get('alerts.rules.cpu_high.critical'));
    }

    public function test_a_database_value_overrides_the_config_default(): void
    {
        $this->settings->set('alerts.rules.cpu_high.warning', 80, 'int');

        $this->assertSame(80, $this->settings->get('alerts.rules.cpu_high.warning', 90));

        $setting = Setting::query()->findOrFail('alerts.rules.cpu_high.warning');
        $this->assertSame('80', $setting->getRawOriginal('value'));
        $this->assertSame('int', $setting->type);
        $this->assertFalse($setting->is_secret);
    }

    public function test_set_invalidates_a_cached_value_within_the_same_request(): void
    {
        $this->settings->set('alerts.reminder_hours', 6, 'int');
        $this->assertSame(6, $this->settings->get('alerts.reminder_hours'));

        $this->settings->set('alerts.reminder_hours', 12, 'int');

        $this->assertSame(12, $this->settings->get('alerts.reminder_hours'));
    }

    public function test_boolean_values_are_cast_on_read(): void
    {
        $this->settings->set('alerts.channels.telegram.enabled', true, 'bool');

        $this->assertTrue($this->settings->get('alerts.channels.telegram.enabled'));
        $this->assertSame('1', Setting::query()->findOrFail('alerts.channels.telegram.enabled')->getRawOriginal('value'));
    }

    public function test_boolean_false_is_stored_as_zero_and_read_back_as_false(): void
    {
        $this->settings->set('alerts.channels.telegram.enabled', false, 'bool');

        $this->assertFalse($this->settings->get('alerts.channels.telegram.enabled'));
        $this->assertSame('0', Setting::query()->findOrFail('alerts.channels.telegram.enabled')->getRawOriginal('value'));
    }

    public function test_non_boolean_values_are_rejected_for_boolean_settings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->set('alerts.channels.telegram.enabled', 'false', 'bool');
    }

    public function test_integer_values_are_rejected_for_boolean_settings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->set('alerts.channels.telegram.enabled', 1, 'boolean');
    }

    public function test_non_scalar_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->set('alerts.invalid', ['nested' => 'value']);
    }

    public function test_object_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->set('alerts.invalid', new \stdClass);
    }

    public function test_a_secret_value_is_stored_as_ciphertext_and_decrypted_on_read(): void
    {
        $key = 'alerts.channels.telegram.bot_token';
        $plaintext = '123456:super-secret-bot-token';

        $this->settings->set($key, $plaintext, 'string', true);

        $setting = Setting::query()->findOrFail($key);

        $this->assertNotSame($plaintext, $setting->getRawOriginal('value'));
        $this->assertTrue($setting->is_secret);

        $this->assertSame($plaintext, $this->settings->get($key));
        $this->assertTrue($this->settings->hasSecret($key));
    }

    public function test_a_secret_type_encrypts_even_when_the_secret_flag_is_false(): void
    {
        $key = 'alerts.channels.telegram.bot_token';
        $plaintext = '123456:super-secret-bot-token';

        $this->settings->set($key, $plaintext, 'secret', false);

        $setting = Setting::query()->findOrFail($key);

        $this->assertNotSame($plaintext, $setting->getRawOriginal('value'));
        $this->assertTrue($setting->is_secret);

        $this->assertSame($plaintext, $this->settings->get($key));
        $this->assertTrue($this->settings->hasSecret($key));
    }

    public function test_a_null_stored_value_falls_back_to_the_config_default(): void
    {
        Setting::query()->create([
            'key' => 'alerts.reminder_hours',
            'value' => null,
            'type' => 'int',
            'is_secret' => false,
        ]);

        $this->assertSame(6, $this->settings->get('alerts.reminder_hours'));
        $this->assertSame(99, $this->settings->get('alerts.reminder_hours', 99));
    }

    public function test_the_cache_stores_only_ciphertext_for_secret_values(): void
    {
        $key = 'alerts.channels.telegram.bot_token';
        $plaintext = '123456:super-secret-bot-token';

        $this->settings->set($key, $plaintext, 'string', true);
        $this->assertSame($plaintext, $this->settings->get($key));

        $cached = Cache::get(SettingsRepository::CACHE_PREFIX.$key);

        $this->assertIsArray($cached);
        $this->assertNotSame($plaintext, $cached['value']);
        $this->assertStringNotContainsString($plaintext, json_encode($cached));
    }

    public function test_overwriting_a_secret_with_a_plain_value_clears_the_flag(): void
    {
        $key = 'alerts.channels.telegram.bot_token';

        $this->settings->set($key, 'secret-value', 'string', true);
        $this->assertTrue($this->settings->hasSecret($key));

        $this->settings->set($key, 'replacement-value', 'string', false);

        $this->assertFalse($this->settings->hasSecret($key));
        $this->assertSame('replacement-value', $this->settings->get($key));
        $this->assertSame('replacement-value', Setting::query()->findOrFail($key)->getRawOriginal('value'));
    }

    public function test_forget_removes_the_database_row_and_the_cache(): void
    {
        $key = 'alerts.reminder_hours';

        $this->settings->set($key, 12, 'int');
        $this->assertSame(12, $this->settings->get($key));

        $this->settings->forget($key);

        $this->assertNull(Setting::query()->find($key));
        $this->assertFalse(Cache::has(SettingsRepository::CACHE_PREFIX.$key));
        $this->assertSame(6, $this->settings->get($key));
    }

    public function test_a_config_default_is_cached_so_repeat_reads_skip_the_database(): void
    {
        $this->assertSame(90, $this->settings->get('alerts.rules.cpu_high.warning'));
        $this->assertTrue(Cache::has(SettingsRepository::CACHE_PREFIX.'alerts.rules.cpu_high.warning'));

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'settings')) {
                $queries++;
            }
        });

        $this->assertSame(90, $this->settings->get('alerts.rules.cpu_high.warning'));
        $this->assertSame(0, $queries);

        // A different key is a genuine cold miss: exactly one lookup.
        $this->assertSame(95, $this->settings->get('alerts.rules.cpu_high.critical'));
        $this->assertSame(1, $queries);
        $this->assertSame(95, $this->settings->get('alerts.rules.cpu_high.critical'));
        $this->assertSame(1, $queries);
    }

    public function test_an_unknown_key_is_cached_as_absent(): void
    {
        $this->assertNull($this->settings->get('alerts.unknown.key'));

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'settings')) {
                $queries++;
            }
        });

        $this->assertNull($this->settings->get('alerts.unknown.key'));
        $this->assertSame(0, $queries);
    }

    public function test_set_overrides_a_cached_config_default_within_the_same_request(): void
    {
        $this->assertSame(90, $this->settings->get('alerts.rules.cpu_high.warning'));

        $this->settings->set('alerts.rules.cpu_high.warning', 80, 'int');

        $this->assertSame(80, $this->settings->get('alerts.rules.cpu_high.warning'));
    }

    public function test_forget_restores_the_cached_config_default(): void
    {
        $this->settings->set('alerts.rules.cpu_high.warning', 80, 'int');
        $this->assertSame(80, $this->settings->get('alerts.rules.cpu_high.warning'));

        $this->settings->forget('alerts.rules.cpu_high.warning');

        $this->assertSame(90, $this->settings->get('alerts.rules.cpu_high.warning'));
    }

    public function test_the_absence_cache_holds_no_value_at_all(): void
    {
        // A key with only a config default (and an unknown key) cache absence,
        // never the resolved value nor anything from config (R22).
        $this->settings->get('alerts.rules.disk_high.warning');
        $this->settings->get('alerts.unknown.key');

        $cached = Cache::get(SettingsRepository::CACHE_PREFIX.'alerts.rules.disk_high.warning');

        $this->assertSame('absent', $cached);
        $this->assertStringNotContainsString('85', (string) $cached);
        $this->assertSame('absent', Cache::get(SettingsRepository::CACHE_PREFIX.'alerts.unknown.key'));
    }
}
