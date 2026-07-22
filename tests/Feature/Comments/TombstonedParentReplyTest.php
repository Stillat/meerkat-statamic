<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Comments;

use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Rules\ValidParentRule;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class TombstonedParentReplyTest extends TestCase
{
    #[Test]
    public function valid_parent_rule_rejects_a_tombstoned_parent(): void
    {
        $parent = CommentFactory::new()->threadId('tomb-parent')->published()->create(['is_removed' => true]);

        $validator = Validator::make(
            ['parent' => $parent->id],
            ['parent' => [new ValidParentRule('tomb-parent', $parent)]],
        );

        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function cp_reply_to_a_tombstoned_parent_is_rejected(): void
    {
        $parent = CommentFactory::new()->threadId('tomb-cp-parent')->published()->create(['is_removed' => true]);
        $this->makeAdmin('tomb-cp-admin');

        $this->postJson(cp_route('meerkat.comment.reply', ['parent' => $parent->id]), [
            'comment' => 'reply under a tombstone',
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ])->assertStatus(422);

        $this->assertSame(0, Comment::query()->where('parent_id', $parent->id)->count());
    }
}
