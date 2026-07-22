<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class SpamReplyCountTest extends TestCase
{
    #[Test]
    public function a_published_reply_flagged_as_spam_does_not_count_toward_the_parent(): void
    {
        $root = CommentFactory::new()->threadId('spam-count')->text('Root')->data(['comment' => 'Root'])->published()->create();
        $reply = CommentFactory::new()->replyTo($root)->text('Reply')->data(['comment' => 'Reply'])->published()->create();

        $this->assertSame(1, $this->freshCount($root));

        $reply->is_spam = true;
        $reply->save();

        $this->assertSame(0, $this->freshCount($root));
    }

    #[Test]
    public function marking_a_spam_reply_as_ham_restores_the_parent_count(): void
    {
        $root = CommentFactory::new()->threadId('spam-count')->text('Root')->data(['comment' => 'Root'])->published()->create();
        $reply = CommentFactory::new()->replyTo($root)->text('Reply')->data(['comment' => 'Reply'])->published()->create(['is_spam' => true]);

        $this->assertSame(0, $this->freshCount($root));

        $reply->is_spam = false;
        $reply->save();

        $this->assertSame(1, $this->freshCount($root));
    }

    #[Test]
    public function thread_metrics_published_count_excludes_spam(): void
    {
        CommentFactory::new()->threadId('spam-metrics')->text('Visible')->data(['comment' => 'Visible'])->published()->create();
        CommentFactory::new()->threadId('spam-metrics')->text('Spammy')->data(['comment' => 'Spammy'])->published()->create(['is_spam' => true]);

        $metric = app(ThreadMetricsManager::class)->recalculateThread('spam-metrics');

        $this->assertSame(1, $metric->published_comments);
        $this->assertSame(1, $metric->spam_comments);
    }

    private function freshCount(Comment $comment): int
    {
        return Comment::query()->findOrFail($comment->id)->replies_count;
    }
}
