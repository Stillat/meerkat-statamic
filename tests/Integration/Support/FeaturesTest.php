<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Support\Features;
use Stillat\Meerkat\Tests\TestCase;

class FeaturesTest extends TestCase
{
    #[Test]
    public function graphql_and_revisions_are_disabled_by_default(): void
    {
        $this->assertFalse(config('meerkat.graphql.enabled'));
        $this->assertFalse(config('meerkat.revisions.enabled'));
        $this->assertFalse(Features::graphql());
        $this->assertFalse(Features::revisions());
    }

    #[Test]
    public function graphql_requires_both_the_config_flag_and_pro(): void
    {
        config()->set('meerkat.graphql.enabled', true);
        config()->set('statamic.editions.pro', true);
        $this->assertTrue(Features::graphql());

        config()->set('statamic.editions.pro', false);
        $this->assertFalse(Features::graphql());

        config()->set('statamic.editions.pro', true);
        config()->set('meerkat.graphql.enabled', false);
        $this->assertFalse(Features::graphql());
    }

    #[Test]
    public function revisions_require_both_the_config_flag_and_pro(): void
    {
        config()->set('meerkat.revisions.enabled', true);
        config()->set('statamic.editions.pro', true);
        $this->assertTrue(Features::revisions());

        config()->set('statamic.editions.pro', false);
        $this->assertFalse(Features::revisions());

        config()->set('statamic.editions.pro', true);
        config()->set('meerkat.revisions.enabled', false);
        $this->assertFalse(Features::revisions());
    }
}
