<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Tests\TestCase;

class SettingsTest extends TestCase
{
    #[Test]
    public function package_config_is_the_fallback_for_unsaved_addon_settings(): void
    {
        config()->set('meerkat.rate_limits.max_attempts', 17);
        config()->set('meerkat.akismet.api_key', 'config-key');

        $this->assertSame(17, Settings::get('rate_limits.max_attempts', 5));
        $this->assertSame('config-key', Settings::get('akismet.api_key'));
    }

    #[Test]
    public function saved_addon_settings_override_package_config_including_falsey_values(): void
    {
        config()->set('meerkat.rate_limits.enabled', true);
        config()->set('meerkat.publishing.automatically_close_comments', 30);
        config()->set('meerkat.authors.anonymous_email', 'fallback@example.com');

        Settings::set('rate_limits.enabled', false);
        Settings::set('publishing.automatically_close_comments', 0);
        Settings::set('authors.anonymous_email');

        $this->assertFalse(Settings::get('rate_limits.enabled', true));
        $this->assertSame(0, Settings::get('publishing.automatically_close_comments', 30));
        $this->assertNull(Settings::get('authors.anonymous_email', 'fallback@example.com'));
    }
}
