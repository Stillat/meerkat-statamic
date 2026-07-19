<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Contracts\CommentRepository as CommentRepositoryContract;
use Stillat\Meerkat\Data\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Hooks\CommentSpamCheck;
use Stillat\Meerkat\Tests\TestCase;

class RepositoryHooksTest extends TestCase
{
    private CommentRepositoryContract $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(CommentRepositoryContract::class);
        $this->resetStatamicHooks();
    }

    #[Test]
    public function deletion_hooks_can_mutate_the_comment_and_receive_subtree_context(): void
    {
        $comment = $this->createComment([
            'comment_text' => 'Original text',
            'is_published' => true,
            'thread_id' => 'hook-delete-thread',
        ]);
        $this->createComment([
            'parent_id' => $comment->id,
            'thread_id' => 'hook-delete-thread',
        ]);
        $payload = null;

        CommentRepository::hook('deletingComment', function (Comment $comment) {
            $comment->comment_text = 'Modified before deletion';

            return $comment;
        });
        CommentRepository::hook('after-comment-deleted', function (mixed $data) use (&$payload) {
            if (! $data instanceof Payload) {
                throw new LogicException('The deletion hook did not receive a payload.');
            }

            $payload = $data;

            return $data;
        });

        $this->repository->deleteComment($comment->id);

        if (! $payload instanceof Payload) {
            throw new LogicException('The deletion hook did not capture its payload.');
        }

        $fresh = $this->requireValue($comment->fresh());
        $this->assertSame('Modified before deletion', $fresh->comment_text);
        $this->assertTrue($fresh->is_removed);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame($comment->id, $payload->id);
        $this->assertTrue($payload->was_published);
        $this->assertSame('hook-delete-thread', $payload->thread_id);
        $this->assertTrue($payload->had_children);
    }

    #[Test]
    public function moderation_hooks_can_modify_spam_ham_rejection_and_restore_operations(): void
    {
        $spam = $this->createComment(['is_spam' => false]);
        $ham = $this->createComment(['is_spam' => true, 'is_ham' => false]);
        $rejected = $this->createComment(['is_published' => true]);
        $restored = $this->createComment([
            'is_removed' => true,
            'removed_at' => now(),
            'removed_by' => 'moderator',
        ]);

        CommentRepository::hook('markingAsSpam', function (Comment $comment) {
            $comment->comment_text = 'SPAM DETECTED';

            return $comment;
        });
        CommentRepository::hook('markingAsHam', function (Comment $comment) {
            $comment->comment_text = 'Verified legitimate';

            return $comment;
        });
        CommentRepository::hook('rejectingComment', function (Comment $comment) {
            $comment->moderation_notes = 'Reviewed by hook';

            return $comment;
        });
        CommentRepository::hook('restoringComment', function (Comment $comment) {
            $comment->comment_text = 'Restored by hook';

            return $comment;
        });

        $this->repository->markAsSpam($spam->id);
        $this->repository->markAsHam($ham->id);
        $this->repository->reject($rejected->id, 'policy');
        $this->repository->restoreComment($restored->id);

        $this->assertTrue($this->requireValue($spam->fresh())->is_spam);
        $this->assertSame('SPAM DETECTED', $this->requireValue($spam->fresh())->comment_text);
        $this->assertTrue($this->requireValue($ham->fresh())->is_ham);
        $this->assertFalse($this->requireValue($ham->fresh())->is_spam);
        $this->assertSame('Verified legitimate', $this->requireValue($ham->fresh())->comment_text);
        $this->assertSame('rejected', $this->requireValue($rejected->fresh())->moderation_status);
        $this->assertSame('Reviewed by hook', $this->requireValue($rejected->fresh())->moderation_notes);
        $this->assertFalse($this->requireValue($restored->fresh())->is_removed);
        $this->assertSame('Restored by hook', $this->requireValue($restored->fresh())->comment_text);
    }

    #[Test]
    public function the_checking_hook_can_short_circuit_spam_guards(): void
    {
        $comment = $this->createComment([
            'comment_text' => 'Ordinary comment',
            'is_spam' => false,
            'checked_for_spam' => false,
        ]);
        $this->createEntry(['id' => $comment->thread_id]);

        CommentSpamCheck::hook('checking', function (mixed $payload) {
            if (! $payload instanceof Payload) {
                throw new LogicException('The checking hook did not receive a payload.');
            }

            $payload->should_check = false;
            $payload->is_spam = true;

            return $payload;
        });

        $this->repository->checkForSpam($comment->id);

        $this->assertTrue($this->requireValue($comment->fresh())->is_spam);
        $this->assertTrue($this->requireValue($comment->fresh())->checked_for_spam);
        $this->assertSame('spam', $this->requireValue($comment->fresh())->moderation_status);
    }

    #[Test]
    public function spam_decision_hooks_can_override_detection_and_destructive_actions(): void
    {
        config([
            'meerkat.spam.auto_check' => true,
            'meerkat.spam.auto_delete' => true,
            'meerkat.spam.auto_unpublish' => true,
        ]);
        $comment = $this->createComment([
            'is_spam' => false,
            'checked_for_spam' => false,
            'is_published' => true,
        ]);
        $this->createEntry(['id' => $comment->thread_id]);

        CommentRepository::hook('after-spam-determined', function (mixed $payload) {
            if (! $payload instanceof Payload) {
                throw new LogicException('The spam decision hook did not receive a payload.');
            }

            $payload->is_spam = true;

            return $payload;
        });
        CommentRepository::hook('spam-action-decided', function (mixed $payload) {
            if (! $payload instanceof Payload) {
                throw new LogicException('The spam action hook did not receive a payload.');
            }

            $payload->should_delete = false;
            $payload->should_unpublish = false;

            return $payload;
        });

        $this->repository->checkForSpam($comment->id);

        $this->assertDatabaseHas('comments', ['id' => $comment->id], 'meerkat');
        $this->assertTrue($this->requireValue($comment->fresh())->is_spam);
        $this->assertTrue($this->requireValue($comment->fresh())->is_published);
    }
}
