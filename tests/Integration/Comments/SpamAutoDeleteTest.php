<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Data\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class SpamAutoDeleteTest extends TestCase
{
    #[Test]
    public function auto_deleted_spam_persists_its_verdict_and_decrements_the_parent_once(): void
    {
        $child = $this->autoDeleteSpamReply('auto-delete-verdict');

        $trashed = $this->requireValue(Comment::withTrashed()->find($child->id));
        $this->assertTrue($trashed->trashed());
        $this->assertTrue($trashed->is_spam);
        $this->assertTrue($trashed->checked_for_spam);
        $this->assertSame('spam', $trashed->moderation_status);
        $this->assertSame(0, $this->requireValue(Comment::query()->find($this->requireValue($trashed->parent_id)))->replies_count);
    }

    #[Test]
    public function purge_spam_matches_auto_deleted_rows(): void
    {
        $child = $this->autoDeleteSpamReply('auto-delete-purge');

        Comment::withTrashed()->whereKey($child->id)->update(['created_at' => now()->subDays(30)]);

        $this->pendingArtisan('meerkat:purge', [
            '--spam' => true,
            '--older-than' => 7,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertNull(Comment::withTrashed()->find($child->id));
    }

    private function autoDeleteSpamReply(string $threadId): Comment
    {
        Settings::set('spam.auto_delete_spam', true);
        config(['meerkat.spam.guards' => []]);
        $this->createEntry(['id' => $threadId]);

        $parent = CommentFactory::new()->threadId($threadId)->depth(0)->published()->create();
        $child = CommentFactory::new()->threadId($threadId)->parent($parent->id)->depth(1)->published()->create();
        $this->assertSame(1, $this->requireValue($parent->fresh())->replies_count);

        CommentRepository::hook('after-spam-determined', function (mixed $payload) {
            if (! $payload instanceof Payload) {
                throw new LogicException('The spam hook did not receive a payload.');
            }

            $payload->is_spam = true;

            return $payload;
        });

        app(CommentRepository::class)->checkForSpam($child->id);

        return $child;
    }
}
