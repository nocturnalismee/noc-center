<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

class SettingsRepository
{
    /**
     * Prefix used for every settings cache entry.
     */
    public const CACHE_PREFIX = 'settings:';

    /**
     * How long a settings row stays cached, in seconds.
     */
    private const CACHE_TTL = 3600;

    /**
     * Sentinel cached when a key has no database row, so a repeat read of a
     * config default (or of an unknown key) does not hit the database again.
     */
    private const ABSENT = 'absent';

    /**
     * Read a setting, falling back to the supplied default (or the matching
     * config value when no default is supplied).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->row($key);

        if ($row === null || $row['value'] === null) {
            return $default ?? config($key);
        }

        $value = $row['value'];

        if ($row['is_secret']) {
            try {
                $value = Crypt::decryptString((string) $value);
            } catch (DecryptException) {
                return $default ?? config($key);
            }
        }

        return $this->cast($value, $row['type']);
    }

    /**
     * Persist a setting override. Secret values are encrypted before they
     * touch the database or the cache.
     */
    public function set(string $key, mixed $value, string $type = 'string', bool $isSecret = false): void
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException('Setting values must be scalar.');
        }

        if (in_array($type, ['bool', 'boolean'], true) && ! is_bool($value)) {
            throw new InvalidArgumentException('Boolean settings require a boolean value.');
        }

        $raw = match ($type) {
            'bool', 'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };

        if ($isSecret || $type === 'secret') {
            $isSecret = true;
            $raw = Crypt::encryptString($raw);
        }

        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $raw,
                'type' => $type,
                'is_secret' => $isSecret,
                'updated_by' => Auth::id(),
            ],
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /**
     * Determine whether the setting currently holds an encrypted secret.
     */
    public function hasSecret(string $key): bool
    {
        $row = $this->row($key);

        return $row !== null && $row['is_secret'];
    }

    /**
     * Remove the database override and its cached copy.
     */
    public function forget(string $key): void
    {
        Setting::query()->whereKey($key)->delete();

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /**
     * Load the cached row, falling back to the database. Only ciphertext is
     * ever cached; decryption happens in memory inside get().
     *
     * Absence is cached too (as a sentinel), so a key that only has a config
     * default is resolved from the database at most once per TTL rather than
     * on every read (R22 is preserved: the sentinel carries no value).
     *
     * @return array{value: string|null, type: string, is_secret: bool}|null
     */
    private function row(string $key): ?array
    {
        $cached = Cache::get(self::CACHE_PREFIX.$key);

        if ($cached === self::ABSENT) {
            return null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        $setting = Setting::query()->find($key);

        if ($setting === null) {
            Cache::put(self::CACHE_PREFIX.$key, self::ABSENT, self::CACHE_TTL);

            return null;
        }

        $row = [
            'value' => $setting->getRawOriginal('value'),
            'type' => (string) $setting->getRawOriginal('type'),
            'is_secret' => (bool) $setting->getRawOriginal('is_secret'),
        ];

        Cache::put(self::CACHE_PREFIX.$key, $row, self::CACHE_TTL);

        return $row;
    }

    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }
}
