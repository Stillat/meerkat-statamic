<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\CommentRepository as CommentRepositoryContract;
use Stillat\Meerkat\Guard\Guards\AkismetGuard;
use Stillat\Meerkat\Tests\TestCase;

class SpamGuardFailureTest extends TestCase
{
    protected CommentRepositoryContract $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(CommentRepositoryContract::class);

        Settings::set('akismet.enabled', true);
        Settings::set('akismet.api_key', 'test-key');
        Settings::set('akismet.blog_url', 'https://example.com');

        config(['meerkat.spam.guards' => [AkismetGuard::class]]);

        Http::fake([
            'https://test-key.rest.akismet.com/1.1/comment-check' => Http::response('error', 500),
        ]);
    }

    #[Test]
    public function a_guard_failure_unpublishes_a_published_comment_when_fail_closed_is_enabled(): void
    {
        config(['meerkat.spam.guard_unpublish_on_guard_failure' => true]);

        $comment = $this->createComment([
            'is_published' => true,
            'is_spam' => false,
            'checked_for_spam' => false,
        ]);
        $this->createEntry(['id' => $comment->thread_id]);

        $this->repository->checkForSpam($comment->id);

        $comment->refresh();
        $this->assertFalse($comment->is_published, 'A guard outage must not leave the comment published when fail-closed is on.');
        $this->assertSame('pending', $comment->moderation_status);
    }

    #[Test]
    public function a_guard_failure_leaves_the_comment_untouched_when_fail_closed_is_disabled(): void
    {
        config(['meerkat.spam.guard_unpublish_on_guard_failure' => false]);

        $comment = $this->createComment([
            'is_published' => true,
            'is_spam' => false,
            'checked_for_spam' => false,
        ]);
        $this->createEntry(['id' => $comment->thread_id]);

        $this->repository->checkForSpam($comment->id);

        $comment->refresh();
        $this->assertTrue($comment->is_published, 'With fail-closed disabled the existing behaviour (publish-through) is preserved.');
    }

    #[Test]
    public function a_guard_failure_does_not_mark_the_comment_as_spam(): void
    {
        config(['meerkat.spam.guard_unpublish_on_guard_failure' => true]);

        $comment = $this->createComment([
            'is_published' => true,
            'is_spam' => false,
            'checked_for_spam' => false,
        ]);
        $this->createEntry(['id' => $comment->thread_id]);

        $this->repository->checkForSpam($comment->id);

        $comment->refresh();
        $this->assertFalse($comment->is_spam);
        $this->assertFalse($comment->checked_for_spam);
    }
}
