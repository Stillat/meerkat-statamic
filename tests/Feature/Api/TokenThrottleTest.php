<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class TokenThrottleTest extends TestCase
{
    #[Test]
    public function public_endpoints_keep_the_tier_default_throttle_of_sixty(): void
    {

        $this->createThread('some-thread');

        $response = $this->get('/api/meerkat/threads/some-thread/stats');

        $response->assertOk();
        $this->assertSame('60', $response->headers->get('X-RateLimit-Limit'));
    }
}
