<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Forms;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Services\SubmissionRateLimiter;
use Stillat\Meerkat\Tests\TestCase;

class RateLimitSpamFloodTest extends TestCase
{
    private const GTUBE = 'XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X';

    #[Test]
    public function rate_limiter_counts_spam_attempts_when_auto_delete_is_on(): void
    {
        Settings::set('spam.auto_delete_spam', true);
        Settings::set('rate_limits.enabled', true);
        Settings::set('rate_limits.max_attempts', 3);

        $this->createEntry(['id' => 'flood-entry']);

        for ($i = 1; $i <= 4; $i++) {
            $this->submitComment([
                '_meerkat_context' => 'flood-entry',
                'comment' => self::GTUBE.' attempt '.$i,
                'name' => 'Spammer',
                'email' => 'spammer@example.com',
            ]);
        }

        $this->assertSame(0, Comment::query()->where('thread_id', 'flood-entry')->count());

        $limiter = app(SubmissionRateLimiter::class);
        $this->assertFalse(
            $limiter->ensureNotLimited('flood-entry', 'spammer@example.com', '127.0.0.1'),
            'Expected the rate limiter to be over threshold after 4 spam-flood attempts.'
        );
    }

    #[Test]
    public function rate_limiter_still_counts_legitimate_submissions(): void
    {
        Settings::set('spam.auto_delete_spam', true);
        Settings::set('spam.auto_check_spam', false);
        Settings::set('rate_limits.enabled', true);
        Settings::set('rate_limits.max_attempts', 3);

        $this->createEntry(['id' => 'legit-flood-entry']);

        for ($i = 1; $i <= 4; $i++) {
            $this->submitComment([
                '_meerkat_context' => 'legit-flood-entry',
                'comment' => 'Hello attempt '.$i,
                'name' => 'Real User',
                'email' => 'real@example.com',
            ]);
        }

        $limiter = app(SubmissionRateLimiter::class);
        $this->assertFalse(
            $limiter->ensureNotLimited('legit-flood-entry', 'real@example.com', '127.0.0.1'),
            'Regression: legitimate submission flood must still trip the limiter.'
        );
    }
}
