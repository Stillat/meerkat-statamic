<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Configuration;

use Illuminate\Support\Arr;
use Statamic\Contracts\Addons\Settings as SettingsContract;
use Statamic\Facades\Addon;
use Statamic\Facades\Blink;

class Settings
{
    public const ADDON_PACKAGE = 'stillat/meerkat';

    private const CACHE_KEY = 'meerkat.settings.instance';

    public static function get(string $key, mixed $default = null): mixed
    {
        $instance = self::instance();

        if ($instance instanceof SettingsContract) {
            $raw = $instance->raw();

            if (Arr::has($raw, $key)) {
                return Arr::get($raw, $key);
            }
        }

        return config('meerkat.'.$key, $default);
    }

    /** @param string|array<string, mixed> $key */
    public static function set(string|array $key, mixed $value = null): void
    {
        self::instance()?->set($key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $values = self::instance()?->all() ?? [];

        return array_filter($values, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    public static function flush(): void
    {
        Blink::forget(self::CACHE_KEY);
    }

    private static function instance(): ?SettingsContract
    {
        $settings = Blink::once(self::CACHE_KEY, fn () => Addon::get(self::ADDON_PACKAGE)->settings());

        return $settings instanceof SettingsContract ? $settings : null;
    }
}
