<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Threads;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class MaxReplyDepthTest extends TestCase
{
    #[Test]
    public function frontend_submission_accepts_the_boundary_and_rejects_the_next_depth(): void
    {
        config()->set('meerkat.publishing.max_reply_depth', 2);
        $this->createEntry(['id' => 'depth-entry']);
        [$root, $depth1, $depth2] = $this->chain('depth-entry', 2);

        $this->submitComment(['_meerkat_context' => 'depth-entry', 'comment' => 'allowed depth 2', 'ids' => (string) $depth1->id])->assertRedirect();
        $this->submitComment(['_meerkat_context' => 'depth-entry', 'comment' => 'rejected depth 3', 'ids' => (string) $depth2->id])->assertSessionHasErrors();

        $this->assertNotNull(Comment::query()->where('comment_text', 'allowed depth 2')->first());
        $this->assertNull(Comment::query()->where('comment_text', 'rejected depth 3')->first());
        $this->assertSame(0, $root->depth);
    }

    #[Test]
    public function null_and_zero_preserve_the_v3_unlimited_convention(): void
    {
        foreach ([null, 0] as $index => $limit) {
            config()->set('meerkat.publishing.max_reply_depth', $limit);
            $thread = 'unlimited-'.$index;
            $this->createEntry(['id' => $thread]);
            $deep = CommentFactory::new()->threadId($thread)->depth(99)->published()->create();
            $body = 'allowed unlimited '.$index;

            $this->submitComment(['_meerkat_context' => $thread, 'comment' => $body, 'ids' => (string) $deep->id])->assertRedirect();
            $this->assertNotNull(Comment::query()->where('comment_text', $body)->first());
        }
    }

    #[Test]
    public function cp_reply_enforces_the_same_depth_limit(): void
    {
        config()->set('meerkat.publishing.max_reply_depth', 1);
        $this->createStatamicCollection('blog', 'Blog');
        $this->makeAdmin('depth-admin', 'depth@example.com');
        [, $depth1] = $this->chain('cp-depth', 1);

        $this->postJson(cp_route('meerkat.comment.reply', ['parent' => $depth1->id]), ['comment' => 'too deep'])
            ->assertUnprocessable();
    }

    #[Test]
    public function template_capability_reports_the_depth_boundary(): void
    {
        config()->set('meerkat.publishing.max_reply_depth', 1);
        $this->createStatamicCollection('blog', 'Blog');
        $this->makeAdmin('depth-template-admin', 'depth-template@example.com');
        $this->createEntry(['id' => 'can-reply']);
        $this->chain('can-reply', 1);

        $result = $this->parseAntlers(<<<'ANTLERS'
{{ meerkat:comments thread="can-reply" }}
[{{ depth }}:{{ if current_user:can_reply }}Y{{ else }}N{{ /if }}]
{{ children }}[{{ depth }}:{{ if current_user:can_reply }}Y{{ else }}N{{ /if }}]{{ /children }}
{{ /meerkat:comments }}
ANTLERS);

        $this->assertStringContainsString('[0:Y]', $result);
        $this->assertStringContainsString('[1:N]', $result);
    }

    /** @return list<Comment> */
    private function chain(string $thread, int $maxDepth): array
    {
        $comments = [CommentFactory::new()->threadId($thread)->depth(0)->published()->create()];
        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $comments[] = CommentFactory::new()->threadId($thread)->parent($comments[$depth - 1]->id)->depth($depth)->published()->create();
        }

        return $comments;
    }
}
