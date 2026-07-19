<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Comments;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Hooks\CommentSpamCheck;
use Stillat\Meerkat\Http\Controllers\CommentController;
use Stillat\Meerkat\Support\Identifiers;
use Stillat\Meerkat\Tests\TestCase;

class HooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetStatamicHooks();
    }

    #[Test]
    public function submission_hooks_run_in_order_and_can_inspect_and_mutate_the_lifecycle(): void
    {
        $entry = $this->createEntry();
        $parent = $this->createComment(['thread_id' => $entry->id()]);
        $order = [];
        $beforePayload = null;
        $afterPayload = null;

        CommentController::hook('creatingComment', function (Comment $comment) use (&$order) {
            $order[] = 'creating';
            $comment->comment_text = 'Changed while creating';

            return $comment;
        });

        CommentController::hook('before-saving-comment', function (mixed $payload) use (&$order, &$beforePayload) {
            if (! $payload instanceof Payload || ! $payload->comment instanceof Comment) {
                throw new LogicException('The before-saving hook did not receive a comment payload.');
            }

            $order[] = 'before-saving';
            $beforePayload = $payload;
            $payload->comment->comment_data = array_merge(
                $payload->comment->comment_data,
                ['enriched_by_hook' => true],
            );

            return $payload;
        });

        CommentController::hook('after-saved-comment', function (mixed $payload) use (&$order, &$afterPayload) {
            if (! $payload instanceof Payload) {
                throw new LogicException('The after-saved hook did not receive a payload.');
            }

            $order[] = 'after-saved';
            $afterPayload = $payload;

            return $payload;
        });

        $this->submitComment([
            '_meerkat_context' => $entry->id(),
            'ids' => $parent->id,
            'comment' => 'Original',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'website' => 'https://example.com',
        ]);

        $comment = Comment::latest('id')->firstOrFail();

        if (! $beforePayload instanceof Payload
            || ! $beforePayload->parent_comment instanceof Comment
            || ! is_array($beforePayload->values)
            || ! $afterPayload instanceof Payload
            || ! $afterPayload->comment instanceof Comment) {
            throw new LogicException('The submission hooks did not capture their expected payloads.');
        }

        $this->assertSame(['creating', 'before-saving', 'after-saved'], $order);
        $this->assertSame('Changed while creating', $comment->comment_text);
        $this->assertTrue($comment->comment_data['enriched_by_hook']);
        $this->assertSame($parent->id, $beforePayload->parent_comment->id);
        $this->assertSame('https://example.com', $beforePayload->values['website']);
        $this->assertSame($comment->id, $afterPayload->comment->id);
        $this->assertSame(Identifiers::visualId($comment->id), $afterPayload->visual_id);
        $this->assertTrue($afterPayload->is_new);
    }

    #[Test]
    public function spam_check_hook_can_short_circuit_the_guard_and_change_the_saved_state(): void
    {
        Settings::set('spam.auto_check_spam', true);
        Settings::set('spam.auto_delete_spam', false);
        Settings::set('spam.auto_unpublish_spam', true);

        $entry = $this->createEntry();

        CommentSpamCheck::hook('checking', function (mixed $payload) {
            if (! $payload instanceof Payload || ! $payload->comment instanceof Comment) {
                throw new LogicException('The spam hook did not receive a comment payload.');
            }

            $payload->should_check = false;
            $payload->is_spam = true;
            $payload->comment->comment_text = 'Marked before guard';

            return $payload;
        });

        $this->submitComment([
            '_meerkat_context' => $entry->id(),
            'comment' => 'Ordinary submission',
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $comment = Comment::latest('id')->firstOrFail();

        $this->assertSame('Marked before guard', $comment->comment_text);
        $this->assertTrue($comment->is_spam);
        $this->assertTrue($comment->checked_for_spam);
        $this->assertFalse($comment->is_published);
    }
}
