<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class ApiRoutePrefixTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.api.enabled', true);
        $app['config']->set('statamic.api.route', 'content-api');
    }

    #[Test]
    public function it_mounts_under_the_customized_statamic_api_prefix(): void
    {
        $this->createThread('prefix-thread');

        $this->getJson('/content-api/meerkat/threads/prefix-thread/stats')->assertOk();
    }

    #[Test]
    public function it_is_not_reachable_under_the_default_prefix_when_customized(): void
    {
        $this->createThread('prefix-thread');

        $this->getJson('/api/meerkat/threads/prefix-thread/stats')->assertNotFound();
    }

    #[Test]
    public function it_wins_over_the_statamic_api_not_found_catch_all(): void
    {
        $this->getJson('/content-api/this-route-does-not-exist')->assertNotFound();

        $this->createThread('catch-all-thread');
        $this->getJson('/content-api/meerkat/threads/catch-all-thread/stats')->assertOk();
    }
}
