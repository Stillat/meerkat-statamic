<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Identity;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Services\SubmissionRateLimiter;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class EmailNormalizationTest extends TestCase
{
    #[Test]
    public function email_case_is_normalized_for_storage_and_rate_limit_identity(): void
    {
        $comment = CommentFactory::new()->author('Me', 'ME@EXAMPLE.COM')->published()->create();
        $this->assertSame('me@example.com', $this->requireValue($comment->fresh())->author_email);

        Settings::set('rate_limits.enabled', true);
        Settings::set('rate_limits.max_attempts', 3);
        $limiter = app(SubmissionRateLimiter::class);
        foreach (['Foo@Example.COM', 'foo@example.com', 'FOO@EXAMPLE.COM'] as $email) {
            $limiter->hit('mixed-case-thread', $email, '127.0.0.1');
        }
        $this->assertFalse($limiter->ensureNotLimited('mixed-case-thread', 'foo@example.com', '127.0.0.1'));
    }
}
