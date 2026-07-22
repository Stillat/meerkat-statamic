<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Contracts\CommentRepository as CommentRepositoryContract;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Jobs\CheckForSpam;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class RepositoryTest extends TestCase
{
    private CommentRepositoryContract $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(CommentRepositoryContract::class);
    }

    #[Test]
    public function it_creates_a_missing_thread_for_an_entry(): void
    {
        $entry = $this->createEntry(['id' => 'new-entry-id', 'title' => 'New Entry']);

        $this->repository->ensureThreadExists($entry);

        $this->assertDatabaseHas('threads', ['thread_id' => 'new-entry-id'], 'meerkat');
    }

    #[Test]
    public function ensure_thread_exists_revives_a_soft_deleted_thread_row(): void
    {
        $entry = $this->createEntry(['id' => 'revive-entry-id', 'title' => 'Revive']);
        $this->repository->ensureThreadExists($entry);
        $this->requireValue(Thread::query()->where('thread_id', 'revive-entry-id')->first())->delete();

        $this->repository->ensureThreadExists($entry);

        $this->assertNull(
            Thread::query()->where('thread_id', 'revive-entry-id')->first()?->deleted_at,
            'A trashed thread row must be revived, not collide with the unique thread_id index.'
        );
    }

    #[Test]
    public function it_queues_only_comments_that_have_not_been_spam_checked(): void
    {
        Queue::fake();

        CommentFactory::new()->create(['checked_for_spam' => false]);
        CommentFactory::new()->create(['checked_for_spam' => false]);
        CommentFactory::new()->create(['checked_for_spam' => true]);

        $this->repository->checkOutstandingForSpam();

        Queue::assertPushed(CheckForSpam::class, 2);
    }

    #[Test]
    public function it_initializes_a_reply_with_the_parents_thread_and_id(): void
    {
        $parent = CommentFactory::new()->text('Parent comment')->create();

        $reply = $this->repository->inReplyTo($parent);

        $this->assertSame($parent->id, $reply->parent_id);
        $this->assertSame($parent->thread_id, $reply->thread_id);
    }

    #[Test]
    public function a_reply_inherits_the_site_of_the_thread_entry_not_the_parent_comment(): void
    {
        $entry = $this->createEntry(['id' => 'reply-site-entry', 'title' => 'Reply Site']);
        $parent = CommentFactory::new()
            ->threadId($entry->id())
            ->text('Parent')
            ->create(['site' => 'a-different-site']);

        $reply = $this->repository->inReplyTo($parent);

        $this->assertSame($this->entryHandle($entry->site()), $reply->site);
        $this->assertNotSame('a-different-site', $reply->site);
    }

    private function entryHandle(object $value): string
    {
        $handle = method_exists($value, 'handle') ? $value->handle() : null;

        return is_string($handle) ? $handle : '';
    }
}
