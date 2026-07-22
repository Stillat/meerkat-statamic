<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Maintenance;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class PurgeCommandTest extends TestCase
{
    #[Test]
    public function purge_validates_target_and_cutoff_arguments(): void
    {
        foreach ([
            [['--older-than' => 30], 'Pick exactly one target'],
            [['--tombstones' => true, '--spam' => true, '--older-than' => 30], 'Pick exactly ONE target'],
            [['--tombstones' => true], '--older-than is required'],
            [['--tombstones' => true, '--older-than' => 0], '--older-than is required'],
        ] as [$arguments, $message]) {
            $this->pendingArtisan('meerkat:purge', $arguments)->expectsOutputToContain($message)->assertExitCode(1);
        }
    }

    #[Test]
    public function tombstone_purge_removes_old_subtrees_without_touching_recent_or_live_rows(): void
    {
        $old = CommentFactory::new()->threadId('purge-tombstone')->create();
        $child = CommentFactory::new()->threadId('purge-tombstone')->parent($old->id)->depth(1)->create();
        Comments::deleteComment($old->id);
        Comment::query()->whereKey($old->id)->update(['removed_at' => now()->subDays(45)]);
        $recent = CommentFactory::new()->threadId('purge-tombstone')->create();
        Comments::deleteComment($recent->id);
        $live = CommentFactory::new()->threadId('purge-tombstone')->create();

        $this->purge(['--tombstones' => true]);

        $this->assertNull(Comment::withTrashed()->find($old->id));
        $this->assertNull(Comment::withTrashed()->find($child->id));
        $this->assertNotNull(Comment::withTrashed()->find($recent->id));
        $this->assertNotNull(Comment::find($live->id));
    }

    #[Test]
    public function spam_purge_selects_only_old_spam(): void
    {
        $oldSpam = CommentFactory::new()->spam()->create();
        Comment::query()->whereKey($oldSpam->id)->update(['created_at' => now()->subDays(45)]);
        $recentSpam = CommentFactory::new()->spam()->create();
        $oldClean = CommentFactory::new()->create();
        Comment::query()->whereKey($oldClean->id)->update(['created_at' => now()->subDays(45)]);

        $this->purge(['--spam' => true]);

        $this->assertNull(Comment::withTrashed()->find($oldSpam->id));
        $this->assertNotNull(Comment::find($recentSpam->id));
        $this->assertNotNull(Comment::find($oldClean->id));
    }

    #[Test]
    public function rejected_purge_selects_only_old_rejected_comments(): void
    {
        $rejected = CommentFactory::new()->create(['moderation_status' => 'rejected']);
        $approved = CommentFactory::new()->create(['moderation_status' => 'approved']);
        Comment::query()->whereIn('comments.id', [$rejected->id, $approved->id])->update(['updated_at' => now()->subDays(45)]);

        $this->purge(['--rejected' => true]);

        $this->assertNull(Comment::withTrashed()->find($rejected->id));
        $this->assertNotNull(Comment::find($approved->id));
    }

    #[Test]
    public function collection_and_thread_scopes_restrict_purge_candidates(): void
    {
        $blog = $this->oldTombstone('blog-thread', 'blog');
        $news = $this->oldTombstone('news-thread', 'news');
        $this->purge(['--tombstones' => true, '--collection' => 'blog']);
        $this->assertNull(Comment::withTrashed()->find($blog->id));
        $this->assertNotNull(Comment::withTrashed()->find($news->id));

        $inThread = $this->oldTombstone('selected-thread', 'news');
        $otherThread = $this->oldTombstone('other-thread', 'news');
        $this->purge(['--tombstones' => true, '--thread' => 'selected-thread']);
        $this->assertNull(Comment::withTrashed()->find($inThread->id));
        $this->assertNotNull(Comment::withTrashed()->find($otherThread->id));
    }

    #[Test]
    public function dry_run_reports_candidates_without_mutation(): void
    {
        $comment = $this->oldTombstone('dry-run', 'blog');

        $this->pendingArtisan('meerkat:purge', ['--tombstones' => true, '--older-than' => 30, '--dry-run' => true])
            ->expectsOutputToContain('Found 1 row')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertNotNull(Comment::withTrashed()->find($comment->id));
    }

    #[Test]
    public function metadata_anonymization_scrubs_only_old_request_data_and_preserves_content_identity(): void
    {
        $old = CommentFactory::new()
            ->author('A', 'a@example.com')
            ->text('old body')
            ->data(['comment' => 'old body'])
            ->create([
                'user_ip' => '203.0.113.1',
                'user_agent' => 'Agent',
                'referer' => 'https://example.com',
            ]);
        Comment::query()->whereKey($old->id)->update(['created_at' => now()->subDays(400)]);
        $recent = CommentFactory::new()->create(['user_ip' => '203.0.113.2', 'user_agent' => 'Recent', 'referer' => 'https://example.com/recent']);

        $this->pendingArtisan('meerkat:purge', ['--anonymize-request-metadata' => true, '--older-than' => 365, '--force' => true])->assertExitCode(0);

        $fresh = $this->requireValue($old->fresh());
        $this->assertNull($fresh->user_ip);
        $this->assertNull($fresh->user_agent);
        $this->assertNull($fresh->referer);
        $this->assertSame('old body', $fresh->comment_text);
        $this->assertSame('A', $fresh->author_name);
        $this->assertSame('a@example.com', $fresh->author_email);
        $this->assertSame('203.0.113.2', $this->requireValue($recent->fresh())->user_ip);
    }

    /** @param array<string, mixed> $arguments */
    private function purge(array $arguments): void
    {
        $this->pendingArtisan('meerkat:purge', array_merge(['--older-than' => 30, '--force' => true], $arguments))->assertExitCode(0);
    }

    private function oldTombstone(string $thread, string $collection): Comment
    {
        $comment = CommentFactory::new()->threadId($thread)->collection($collection)->create();
        Comments::deleteComment($comment->id);
        Comment::query()->whereKey($comment->id)->update(['removed_at' => now()->subDays(45)]);

        return $comment;
    }
}
