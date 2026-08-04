<?php

namespace App\Support\Platform;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/**
 * Read / write platform-wide configuration a platform admin edits from the UI.
 *
 * The service caches the full key/value bag under a single cache entry so a
 * request that reads twenty settings costs one database query, not twenty,
 * and so the config-override service provider does not stampede on boot.
 *
 * Values missing from the database fall through to the framework's normal
 * config lookup, which usually resolves to a matching .env entry. That
 * means the app keeps working before an admin has ever visited Settings.
 */
class PlatformSettings
{
    protected const CACHE_KEY = 'platform.settings.v1';

    /** @var array<string, string|null>|null */
    protected ?array $memo = null;

    /**
     * Read a stored setting, falling back to `config($fallbackKey)` if the
     * row does not exist. Casting is left to the caller because settings
     * span string, boolean and numeric shapes and forcing a single cast
     * here would drop precision on money.
     */
    public function get(string $key, ?string $fallbackConfigKey = null, mixed $default = null): mixed
    {
        $stored = $this->bag()[$key] ?? null;

        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        if ($fallbackConfigKey !== null) {
            $configValue = config($fallbackConfigKey);

            if ($configValue !== null && $configValue !== '') {
                return $configValue;
            }
        }

        return $default;
    }

    public function getBool(string $key, ?string $fallbackConfigKey = null, bool $default = false): bool
    {
        $value = $this->get($key, $fallbackConfigKey, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getFloat(string $key, ?string $fallbackConfigKey = null, float $default = 0.0): float
    {
        $value = $this->get($key, $fallbackConfigKey, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function getInt(string $key, ?string $fallbackConfigKey = null, int $default = 0): int
    {
        $value = $this->get($key, $fallbackConfigKey, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Persist a setting. Empty strings are stored as null so an admin who
     * clears a field disables the override rather than replacing it with
     * an empty value the app would then read as valid.
     */
    public function set(string $key, mixed $value, ?Authenticatable $actor = null): void
    {
        $stored = ($value === '' || $value === null)
            ? null
            : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

        PlatformSetting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $stored,
                'updated_by_user_id' => $actor?->getAuthIdentifier(),
            ],
        );

        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, ?Authenticatable $actor = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $actor);
        }
    }

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return $this->bag();
    }

    /**
     * Drop caches so the next read pulls fresh values from the DB. Called
     * automatically after every write; exposed publicly so tests and the
     * settings page can force a re-read after a manual DB fiddle.
     */
    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     */
    protected function bag(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        // Ten minutes is a middle-ground: an admin editing settings sees
        // their own change immediately (writes call flush()), while a
        // browsing tenant never triggers a DB query per request.
        return $this->memo = Cache::remember(self::CACHE_KEY, 600, function (): array {
            return PlatformSetting::query()
                ->pluck('value', 'key')
                ->all();
        });
    }
}
