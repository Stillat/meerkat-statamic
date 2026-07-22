<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Services;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Services\SubmissionRateLimiter;
use Stillat\Meerkat\Tests\TestCase;

class SubmissionRateLimiterTest extends TestCase
{
    #[Test]
    public function it_allows_submissions_under_threshold(): void
    {
        Settings::set('rate_limits.enabled', true);
        Settings::set('rate_limits.max_attempts', 5);

        $limiter = app(SubmissionRateLimiter::class);

        $this->assertTrue($limiter->ensureNotLimited('rl-1', 'a@e.com', '127.0.0.1'));

        for ($i = 0; $i < 4; $i++) {
            $limiter->hit('rl-1', 'a@e.com', '127.0.0.1');
        }

        $this->assertTrue($limiter->ensureNotLimited('rl-1', 'a@e.com', '127.0.0.1'));
    }

    #[Test]
    public function it_blocks_submissions_over_threshold(): void
    {
        Settings::set('rate_limits.enabled', true);
        Settings::set('rate_limits.max_attempts', 3);

        $limiter = app(SubmissionRateLimiter::class);

        for ($i = 0; $i < 3; $i++) {
            $limiter->hit('rl-2', 'b@e.com', '127.0.0.1');
        }

        $this->assertFalse($limiter->ensureNotLimited('rl-2', 'b@e.com', '127.0.0.1'));
    }

    #[Test]
    public function it_is_a_no_op_when_disabled(): void
    {
        Settings::set('rate_limits.enabled', false);

        $limiter = app(SubmissionRateLimiter::class);

        for ($i = 0; $i < 100; $i++) {
            $limiter->hit('rl-3', 'c@e.com', '127.0.0.1');
        }

        $this->assertTrue($limiter->ensureNotLimited('rl-3', 'c@e.com', '127.0.0.1'));
    }
}
