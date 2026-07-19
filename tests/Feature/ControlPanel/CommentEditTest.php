<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\ControlPanel;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class CommentEditTest extends TestCase
{
    #[Test]
    public function admin_update_persists_content_identity_and_moderation_metadata(): void
    {
        $this->actAsAdmin();
        $comment = CommentFactory::new()->threadId('cp-edit')->author('Original', 'original@example.com')->text('Original body')->data(['comment' => 'Original body'])->published(false)->create();

        $this->putJson(cp_route('meerkat.comment.update', ['id' => $comment->id]), $this->payload($comment, [
            'comment' => 'Updated body',
            'name' => 'Updated Author',
            'email' => 'updated@example.com',
            'moderation_status' => 'approved',
            'moderation_notes' => 'Reviewed',
        ]))->assertSuccessful();

        $fresh = $this->requireValue($comment->fresh());
        $this->assertSame('Updated body', $fresh->comment_text);
        $this->assertSame('Updated Author', $fresh->author_name);
        $this->assertSame('updated@example.com', $fresh->author_email);
        $this->assertSame('approved', $fresh->moderation_status);
        $this->assertSame('Reviewed', $fresh->moderation_notes);
        $this->assertTrue($fresh->is_published);
        $this->assertSame('cp-edit-admin', $fresh->moderated_by);
    }

    #[Test]
    public function rejected_and_pending_statuses_enforce_their_canonical_flags(): void
    {
        $this->actAsAdmin();
        $rejected = CommentFactory::new()->threadId('cp-status')->published()->spam()->create();
        $pending = CommentFactory::new()->threadId('cp-status')->published()->create();

        $this->putJson(cp_route('meerkat.comment.update', ['id' => $rejected->id]), $this->payload($rejected, [
            'is_published' => true,
            'moderation_status' => 'rejected',
            'moderation_reason' => 'spam-link',
        ]))->assertSuccessful();
        $this->putJson(cp_route('meerkat.comment.update', ['id' => $pending->id]), $this->payload($pending, [
            'is_published' => true,
            'moderation_status' => 'pending',
        ]))->assertSuccessful();

        $this->assertFalse($this->requireValue($rejected->fresh())->is_published);
        $this->assertFalse($this->requireValue($rejected->fresh())->is_spam);
        $this->assertFalse($this->requireValue($rejected->fresh())->is_ham);
        $this->assertSame('spam-link', $this->requireValue($rejected->fresh())->moderation_reason);
        $this->assertFalse($this->requireValue($pending->fresh())->is_published);
        $this->assertSame('pending', $this->requireValue($pending->fresh())->moderation_status);
    }

    private function actAsAdmin(): void
    {
        $this->createStatamicCollection('blog', 'Blog');
        $this->makeAdmin('cp-edit-admin', 'admin@example.com');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Comment $comment, array $overrides): array
    {
        return array_merge([
            'comment' => $comment->comment_text,
            'name' => $comment->author_name,
            'email' => $comment->author_email,
            'is_published' => (bool) $comment->is_published,
            'is_spam' => (bool) $comment->is_spam,
            'moderation_status' => $comment->moderation_status ?? 'approved',
            'moderation_reason' => $comment->moderation_reason,
            'moderation_notes' => $comment->moderation_notes,
            'thread_id' => [$comment->thread_id],
            'collection' => [$comment->collection],
            'site' => [$comment->site],
            'author_id' => [],
            'created_at' => $this->statamicDate($comment->created_at ?? now()),
        ], $overrides);
    }
}
