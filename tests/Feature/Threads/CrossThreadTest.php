<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Threads;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class CrossThreadTest extends TestCase
{
    #[Test]
    public function explicit_thread_lists_and_the_from_thread_alias_select_only_requested_threads(): void
    {
        $this->comment('thread-a', 'Comment A');
        $this->comment('thread-b', 'Comment B');
        $this->comment('thread-c', 'Comment C');

        foreach (['thread="thread-a|thread-b"', 'from_thread="thread-a|thread-b"'] as $attribute) {
            $result = $this->parseAntlers("{{ meerkat:comments {$attribute} }}[{{ comment }}]{{ /meerkat:comments }}");
            $this->assertStringContainsString('[Comment A]', $result);
            $this->assertStringContainsString('[Comment B]', $result);
            $this->assertStringNotContainsString('[Comment C]', $result);
        }
    }

    #[Test]
    public function wildcard_applies_public_visibility_and_standard_collection_conditions(): void
    {
        $this->comment('blog-thread', 'Blog visible', 'blog');
        $this->comment('news-thread', 'News visible', 'news');
        CommentFactory::new()->threadId('hidden-thread')->text('Unpublished')->data(['comment' => 'Unpublished'])->unpublished()->create();
        CommentFactory::new()->threadId('spam-thread')->text('Spam')->data(['comment' => 'Spam'])->published()->spam()->create();
        $removed = CommentFactory::new()->threadId('removed-thread')->text('Removed')->data(['comment' => 'Removed'])->published()->removed()->create();
        CommentFactory::new()->threadId('removed-thread')->parent($removed->id)->depth(1)->text('Removed child')->data(['comment' => 'Removed child'])->published()->create();

        $all = $this->parseAntlers('{{ meerkat:comments thread="*" }}[{{ comment }}]{{ children }}[{{ comment }}]{{ /children }}{{ /meerkat:comments }}');
        $this->assertStringContainsString('Blog visible', $all);
        $this->assertStringContainsString('News visible', $all);
        foreach (['Unpublished', 'Spam', 'Removed', 'Removed child'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $all);
        }

        $news = $this->parseAntlers('{{ meerkat:comments thread="*" collection:is="news" }}[{{ comment }}]{{ /meerkat:comments }}');
        $this->assertStringContainsString('News visible', $news);
        $this->assertStringNotContainsString('Blog visible', $news);
    }

    #[Test]
    public function wildcard_count_is_explicitly_unsupported(): void
    {
        $this->comment('count-thread', 'Count me');

        $this->assertSame('0', trim($this->parseAntlers('{{ meerkat:comment_count thread="*" }}')));
    }

    private function comment(string $thread, string $text, string $collection = 'blog'): void
    {
        CommentFactory::new()->threadId($thread)->collection($collection)->text($text)->data(['comment' => $text])->published()->create();
    }
}
