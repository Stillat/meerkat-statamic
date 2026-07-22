<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Identity;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class DeletedUserAuthorScopeTest extends TestCase
{
    #[Test]
    public function author_details_are_not_resolved_from_soft_deleted_users_meta(): void
    {
        UserMeta::create(['user_id' => 'scope-user', 'name' => 'Meta Name', 'email' => 'meta@example.com']);
        $comment = CommentFactory::new()->threadId('scope-thread')->authorId('scope-user')->create();

        $this->assertSame('Meta Name', $this->requireValue(Comment::query()->find($comment->id))->name);

        $this->requireValue(UserMeta::query()->where('user_id', 'scope-user')->first())->delete();

        $fresh = $this->requireValue(Comment::query()->find($comment->id));
        $this->assertSame('Anonymous User', $fresh->name);
        $this->assertSame('no-email@example.org', $fresh->email);
    }
}
