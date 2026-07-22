<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Tags;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class HiddenRepliesRenderingTest extends TestCase
{
    #[Test]
    public function unpublished_spam_and_trashed_replies_never_render_for_guests(): void
    {
        $this->createEntry(['id' => 'hidden-replies-thread']);

        $root = CommentFactory::new()
            ->threadId('hidden-replies-thread')
            ->text('Visible root')
            ->data(['comment' => 'Visible root'])
            ->published()
            ->create();

        CommentFactory::new()->replyTo($root)
            ->text('Visible reply')->data(['comment' => 'Visible reply'])
            ->published()->create();
        CommentFactory::new()->replyTo($root)
            ->text('Pending reply')->data(['comment' => 'Pending reply'])
            ->unpublished()->create();
        CommentFactory::new()->replyTo($root)
            ->text('Spam reply')->data(['comment' => 'Spam reply'])
            ->published()->create(['is_spam' => true]);
        CommentFactory::new()->replyTo($root)
            ->text('Trashed reply')->data(['comment' => 'Trashed reply'])
            ->published()->create()
            ->delete();

        $result = $this->parseAntlers(
            '{{ meerkat:comments thread="hidden-replies-thread" }}{{ comment_html }}'
            .'{{ children }}{{ comment_html }}{{ /children }}{{ /meerkat:comments }}',
        );

        $this->assertStringContainsString('Visible root', $result);
        $this->assertStringContainsString('Visible reply', $result);

        foreach (['Pending reply', 'Spam reply', 'Trashed reply'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $result);
        }
    }

    #[Test]
    public function nested_hidden_replies_are_filtered_at_every_depth(): void
    {
        $this->createEntry(['id' => 'hidden-depth-thread']);

        $root = CommentFactory::new()
            ->threadId('hidden-depth-thread')
            ->text('Root')->data(['comment' => 'Root'])
            ->published()->create();
        $child = CommentFactory::new()->replyTo($root)
            ->text('Child')->data(['comment' => 'Child'])
            ->published()->create();
        CommentFactory::new()->replyTo($child)
            ->text('Hidden grandchild')->data(['comment' => 'Hidden grandchild'])
            ->unpublished()->create();

        $result = $this->parseAntlers(
            '{{ meerkat:comments thread="hidden-depth-thread" }}{{ comment_html }}'
            .'{{ children }}{{ comment_html }}{{ children }}{{ comment_html }}{{ /children }}{{ /children }}{{ /meerkat:comments }}',
        );

        $this->assertStringContainsString('Child', $result);
        $this->assertStringNotContainsString('Hidden grandchild', $result);
    }
}
