<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Forms;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Services\SubmissionRateLimiter;
use Stillat\Meerkat\Tests\TestCase;

class RateLimitIpBucketTest extends TestCase
{
    #[Test]
    public function single_ip_rotating_email_is_rate_limited(): void
    {
        Settings::set('rate_limits.enabled', true);
        Settings::set('rate_limits.max_attempts', 3);

        $this->createEntry(['id' => 'ip-rotate-thread']);

        $response = null;

        for ($i = 1; $i <= 4; $i++) {
            $response = $this->submitComment([
                '_meerkat_context' => 'ip-rotate-thread',
                'comment' => 'attempt '.$i,
                'name' => 'Spinner '.$i,
                'email' => 'addr'.$i.'@example.com',
            ]);
        }

        $response->assertSessionHasErrors(['comment' => __('meerkat::validation.rate_limited')], null, 'meerkat');

        $this->assertSame(3, Comment::query()->where('thread_id', 'ip-rotate-thread')->count(),
            'Only the first three (the IP bucket limit) should have saved.');

        $limiter = app(SubmissionRateLimiter::class);
        $this->assertFalse(
            $limiter->ensureNotLimited('ip-rotate-thread', 'fifth@example.com', '127.0.0.1')
        );
    }

    #[Test]
    public function different_ips_each_get_their_own_bucket(): void
    {
        Settings::set('rate_limits.enabled', true);
        Settings::set('rate_limits.max_attempts', 3);

        $limiter = app(SubmissionRateLimiter::class);

        for ($i = 0; $i < 3; $i++) {
            $limiter->hit('shared-thread', 'a@example.com', '10.0.0.1');
        }

        $this->assertTrue(
            $limiter->ensureNotLimited('shared-thread', 'b@example.com', '10.0.0.2'),
            'IP B should be unaffected by IP A filling its own bucket.'
        );

        $this->assertFalse(
            $limiter->ensureNotLimited('shared-thread', 'a@example.com', '10.0.0.1')
        );
    }

    #[Test]
    public function legacy_single_user_flood_still_trips_the_composite_bucket(): void
    {
        Settings::set('rate_limits.enabled', true);
        Settings::set('rate_limits.max_attempts', 3);

        $limiter = app(SubmissionRateLimiter::class);

        for ($i = 0; $i < 3; $i++) {
            $limiter->hit('legacy-thread', 'same@example.com', '127.0.0.1');
        }

        $this->assertFalse(
            $limiter->ensureNotLimited('legacy-thread', 'same@example.com', '127.0.0.1'),
            'Regression: an attacker who reuses the same email still trips after max_attempts.'
        );
    }
}
