<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Cached read/write access to the settings table.
 * Usage: app(Settings::class)->get('finance', 'invoice_currency', 'USD').
 */
class Settings
{
    private const CACHE_KEY = 'app.settings';

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /**
     * A map of "group.key" => already-typed value. Only primitives/arrays are
     * cached — never Eloquent models — so reading the cache from a fresh CLI
     * process can never produce an incomplete-class error (a driver-agnostic
     * robustness fix; the returned values are identical either way).
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = Cache::rememberForever(self::CACHE_KEY, function () {
                return Setting::all()
                    ->mapWithKeys(fn (Setting $s) => ["{$s->group}.{$s->key}" => $s->typedValue()])
                    ->all();
            });
        }

        return $this->cache;
    }

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        $composite = "{$group}.{$key}";

        return array_key_exists($composite, $all) ? $all[$composite] : $default;
    }

    public function set(string $group, string $key, mixed $value, string $type = 'string'): void
    {
        Setting::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $this->stringify($value, $type), 'type' => $type],
        );

        $this->flush();
    }

    /**
     * Bulk set multiple settings within one group.
     *
     * @param  array<string,array{value:mixed,type:string}|mixed>  $values
     */
    public function setMany(string $group, array $values): void
    {
        foreach ($values as $key => $entry) {
            if (is_array($entry) && array_key_exists('value', $entry)) {
                $this->set($group, $key, $entry['value'], $entry['type'] ?? 'string');
            } else {
                $this->set($group, $key, $entry);
            }
        }
    }

    public function flush(): void
    {
        $this->cache = null;
        Cache::forget(self::CACHE_KEY);
    }

    private function stringify(mixed $value, string $type): ?string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => $value === null ? null : (string) $value,
        };
    }
}
