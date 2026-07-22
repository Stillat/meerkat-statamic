<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Forms;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class AuthenticatedReplyTest extends TestCase
{
    #[Test]
    public function a_member_with_only_submit_comments_can_reply(): void
    {
        $this->createEntry(['id' => 'test-entry-id']);
        $parent = CommentFactory::new()->threadId('test-entry-id')->published()->create();

        $this->actingAs($this->userWithPermissions('submit comments'));

        $this->submitComment([
            'comment' => 'an authenticated reply',
            'ids' => (string) $parent->id,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(
            Comment::query()->where('parent_id', $parent->id)->first(),
            'Replying must not require CP collection or site permissions.'
        );
    }
}
