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
    public function marking_ham_returns_a_never_approved_comment_to_the_moderation_queue(): void
    {
        Settings::set('spam.auto_unpublish_spam', true);
        $comment = CommentFactory::new()->published(false)->create();

        $this->assertTrue(Comments::markAsSpam($comment->id));
        $this->assertTrue(Comments::markAsHam($comment->id));

        $fresh = $this->requireValue($comment->fresh());
        $this->assertFalse($fresh->is_published, 'Ham must not bypass the moderation queue for a comment that was never approved.');
        $this->assertSame('pending', $fresh->moderation_status);
    }

    #[Test]
    public function ham_republishes_when_a_cp_diff_audit_recorded_the_published_state(): void
    {
        $comment = CommentFactory::new()->published(false)->spam()->create();

        CommentModerationAudit::query()->create([
            'comment_id' => $comment->id,
            'actor_id' => null,
            'action' => 'marked_spam',
            'details' => [
                'moderation_status' => ['from' => 'approved', 'to' => 'spam'],
                'is_published' => ['from' => true, 'to' => false],
                'is_spam' => ['from' => false, 'to' => true],
            ],
        ]);

        $this->assertTrue(Comments::markAsHam($comment->id));
        $this->assertTrue($this->requireValue($comment->fresh())->is_published);
    }

    #[Test]
    public function repeated_spam_flags_preserve_the_original_published_evidence(): void
    {
        $comment = CommentFactory::new()->published()->create();

        $this->assertTrue(Comments::markAsSpam($comment->id));
        $this->assertTrue(Comments::markAsSpam($comment->id));
        $this->assertTrue(Comments::markAsHam($comment->id));

        $this->assertTrue(
            $this->requireValue($comment->fresh())->is_published,
            'A re-flag (e.g. via a bulk selection) must not shadow the original was-published evidence.'
        );
    }

    #[Test]
    public function a_clean_spam_recheck_preserves_a_moderator_rejection(): void
    {
        $this->createEntry(['id' => 'recheck-entry']);
        $comment = CommentFactory::new()->threadId('recheck-entry')->published()->create();

        $this->assertTrue(Comments::reject($comment->id, 'off-topic'));
        Comments::checkForSpam($comment->id);

        $fresh = $this->requireValue($comment->fresh());
        $this->assertSame('rejected', $fresh->moderation_status);
        $this->assertSame('off-topic', $fresh->moderation_reason);
        $this->assertFalse($fresh->is_published);
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
