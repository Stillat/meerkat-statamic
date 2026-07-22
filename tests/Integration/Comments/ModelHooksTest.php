<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Tests\TestCase;

class ModelHooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetStatamicHooks();
    }

    #[Test]
    public function saving_and_saved_hooks_receive_create_and_update_context_and_can_mutate_the_model(): void
    {
        $events = [];

        Comment::hook('saving', function (mixed $payload) use (&$events) {
            if (! $payload instanceof Payload || ! $payload->model instanceof Comment) {
                throw new LogicException('The saving hook did not receive a comment payload.');
            }

            $events[] = ['saving', $payload->is_creating, $payload->model->id];

            if ($payload->is_creating) {
                $payload->model->comment_text = 'Changed by saving hook';
            }

            return $payload;
        });

        Comment::hook('saved', function (mixed $payload) use (&$events) {
            if (! $payload instanceof Payload || ! $payload->model instanceof Comment) {
                throw new LogicException('The saved hook did not receive a comment payload.');
            }

            $events[] = ['saved', $payload->was_created, $payload->model->id];

            return $payload;
        });

        $comment = $this->createComment(['comment_text' => 'Original']);
        $this->assertSame('Changed by saving hook', $comment->comment_text);

        $comment->comment_text = 'Updated';
        $comment->save();

        $this->assertSame([
            ['saving', true, null],
            ['saved', true, $comment->id],
            ['saving', false, $comment->id],
            ['saved', false, $comment->id],
        ], $events);
    }

    #[Test]
    public function legacy_model_hook_aliases_still_run(): void
    {
        $events = [];

        Comment::hook('before-saving-comment-model', function ($payload) use (&$events) {
            $events[] = 'before';

            return $payload;
        });
        Comment::hook('after-saved-comment-model', function ($payload) use (&$events) {
            $events[] = 'after';

            return $payload;
        });

        $this->createComment();

        $this->assertSame(['before', 'after'], $events);
    }
}
