<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ModerationActionsTest extends TestCase
{
    #[Test]
    public function moderation_transitions_persist_state_metadata_and_audits(): void
    {
        Settings::set('spam.auto_unpublish_spam', true);
        $comment = CommentFactory::new()->published(false)->create();

        $this->assertTrue(Comments::publish($comment->id));
        $this->assertTrue($this->requireValue($comment->fresh())->is_published);
        $this->assertSame('approved', $this->requireValue($comment->fresh())->moderation_status);

        $this->assertTrue(Comments::unpublish($comment->id));
        $this->assertFalse($this->requireValue($comment->fresh())->is_published);

        Comments::publish($comment->id);
        $this->assertTrue(Comments::markAsSpam($comment->id));
        $this->assertTrue($this->requireValue($comment->fresh())->is_spam);
        $this->assertFalse($this->requireValue($comment->fresh())->is_published);
        $this->assertSame('spam', $this->requireValue($comment->fresh())->moderation_status);

        $this->assertTrue(Comments::markAsHam($comment->id));
        $this->assertTrue($this->requireValue($comment->fresh())->is_ham);
        $this->assertFalse($this->requireValue($comment->fresh())->is_spam);
        $this->assertTrue($this->requireValue($comment->fresh())->is_published);

        $this->assertTrue(Comments::reject($comment->id, 'Off-topic', 'Does not belong here'));
        $fresh = $this->requireValue($comment->fresh());
        $this->assertFalse($fresh->is_published);
        $this->assertSame('rejected', $fresh->moderation_status);
        $this->assertSame('Off-topic', $fresh->moderation_reason);
        $this->assertSame('Does not belong here', $fresh->moderation_notes);

        $actions = CommentModerationAudit::query()
            ->where('comment_id', $comment->id)
            ->pluck('action')
            ->all();

        $this->assertContains('published', $actions);
        $this->assertContains('marked_spam', $actions);
        $this->assertContains('marked_ham', $actions);
        $this->assertContains('rejected', $actions);
    }

    #[Test]
    public function bulk_moderation_counts_only_existing_comments_and_applies_each_transition(): void
    {
        $comments = collect([
            CommentFactory::new()->published(false)->create(),
            CommentFactory::new()->published(false)->create(),
        ]);
        $ids = $this->requireIntegerList($comments->pluck('id')->all());

        $this->assertSame(2, Comments::bulkApprove([...$ids, 999_999]));
        $this->assertTrue($comments->every(fn ($comment) => $this->requireValue($comment->fresh())->is_published));

        $this->assertSame(2, Comments::bulkSpam($ids));
        $this->assertTrue($comments->every(fn ($comment) => $this->requireValue($comment->fresh())->is_spam));

        $this->assertSame(2, Comments::bulkReject($ids, 'spam-link'));
        $this->assertTrue($comments->every(
            fn ($comment) => $this->requireValue($comment->fresh())->moderation_status === 'rejected'
                && $this->requireValue($comment->fresh())->moderation_reason === 'spam-link',
        ));

        $this->assertSame(2, Comments::bulkDelete($ids));
        $this->assertTrue($comments->every(
            fn ($comment) => $this->requireValue($comment->fresh())->is_removed
                && $this->requireValue($comment->fresh())->removed_at !== null
                && $this->requireValue($comment->fresh())->deleted_at === null,
        ));
    }
}
