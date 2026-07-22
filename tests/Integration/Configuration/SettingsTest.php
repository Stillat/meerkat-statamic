<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Configuration;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use Statamic\CP\PublishForm;
use Statamic\Facades\Addon;
use Statamic\Fields\Blueprint;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Tests\TestCase;

class SettingsTest extends TestCase
{
    #[Test]
    public function akismet_settings_saved_through_the_cp_blueprint_land_where_the_client_reads_them(): void
    {
        config()->set('meerkat.akismet.enabled', false);
        config()->set('meerkat.akismet.api_key');

        $blueprint = Addon::get(Settings::ADDON_PACKAGE)->settingsBlueprint();

        if (! $blueprint instanceof Blueprint) {
            $this->fail('Expected the Meerkat settings blueprint to be registered.');
        }

        $values = PublishForm::make($blueprint)->submit([
            'akismet' => [
                'enabled' => true,
                'api_key' => 'cp-entered-key',
                'blog_url' => 'https://example.com',
                'comment_type' => 'comment',
            ],
        ]);

        $this->assertTrue((bool) Arr::get($values, 'akismet.enabled'));
        $this->assertSame('cp-entered-key', Arr::get($values, 'akismet.api_key'));
        $this->assertSame('https://example.com', Arr::get($values, 'akismet.blog_url'));
        $this->assertFalse(Arr::has($values, 'spam.akismet'));
    }

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
