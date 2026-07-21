<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Tags;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class CommentTagParametersTest extends TestCase
{
    private function seedThread(string $threadId, int $count): void
    {
        $this->createEntry(['id' => $threadId]);

        for ($i = 1; $i <= $count; $i++) {
            CommentFactory::new()
                ->threadId($threadId)
                ->text('Comment '.$i)
                ->data(['comment' => 'Comment '.$i])
                ->published()
                ->create(['created_at' => Carbon::parse('2026-01-01 12:00:00')->addMinutes($i)]);
        }
    }

    #[Test]
    public function paginate_true_uses_the_limit_param_as_the_page_size(): void
    {
        $this->seedThread('paginate-true', 7);

        $result = $this->parseAntlers(
            '{{ meerkat:comments thread="paginate-true" paginate="true" limit="3" }}'
            .'{{ comments }}[{{ comment }}]{{ /comments }}'
            .'|pages:{{ paginate:total_pages }}|total:{{ total_results }}'
            .'{{ /meerkat:comments }}',
        );

        $this->assertSame(3, substr_count($result, '['));
        $this->assertStringContainsString('[Comment 1]', $result);
        $this->assertStringContainsString('[Comment 3]', $result);
        $this->assertStringContainsString('|pages:3', $result);
        $this->assertStringContainsString('|total:7', $result);
    }

    #[Test]
    public function integer_paginate_values_remain_the_page_size(): void
    {
        $this->seedThread('paginate-int', 7);

        $result = $this->parseAntlers(
            '{{ meerkat:comments thread="paginate-int" paginate="5" }}'
            .'{{ comments }}[{{ comment }}]{{ /comments }}|pages:{{ paginate:total_pages }}{{ /meerkat:comments }}',
        );

        $this->assertSame(5, substr_count($result, '['));
        $this->assertStringContainsString('|pages:2', $result);
    }

    #[Test]
    public function limit_and_offset_apply_to_the_unpaginated_roots_query(): void
    {
        $this->seedThread('limit-offset', 5);

        $template = '[{{ comment }}]';
        $limited = $this->parseAntlers('{{ meerkat:comments thread="limit-offset" limit="2" }}'.$template.'{{ /meerkat:comments }}');
        $offset = $this->parseAntlers('{{ meerkat:comments thread="limit-offset" limit="2" offset="2" }}'.$template.'{{ /meerkat:comments }}');
        $offsetOnly = $this->parseAntlers('{{ meerkat:comments thread="limit-offset" offset="4" }}'.$template.'{{ /meerkat:comments }}');

        $this->assertSame('[Comment 1][Comment 2]', trim($limited));
        $this->assertSame('[Comment 3][Comment 4]', trim($offset));
        $this->assertSame('[Comment 5]', trim($offsetOnly));
    }

    #[Test]
    public function offset_and_limit_apply_while_paginating(): void
    {
        $this->seedThread('paginate-offset', 7);

        $result = $this->parseAntlers(
            '{{ meerkat:comments thread="paginate-offset" paginate="2" offset="1" limit="4" }}'
            .'{{ comments }}[{{ comment }}]{{ /comments }}'
            .'|pages:{{ paginate:total_pages }}|total:{{ total_results }}'
            .'{{ /meerkat:comments }}',
        );

        // Offset skips Comment 1; limit caps the paginated set at 4 (2 pages).
        $this->assertSame('[Comment 2][Comment 3]', implode('', array_slice(explode('|', trim($result)), 0, 1)));
        $this->assertStringContainsString('|pages:2', $result);
        $this->assertStringContainsString('|total:4', $result);
    }

    #[Test]
    public function the_select_param_is_ignored_without_breaking_rendering(): void
    {
        $this->createEntry(['id' => 'select-thread']);
        $root = CommentFactory::new()
            ->threadId('select-thread')
            ->text('Root body')
            ->data(['comment' => 'Root body'])
            ->published()
            ->create();
        CommentFactory::new()
            ->replyTo($root)
            ->text('Child body')
            ->data(['comment' => 'Child body'])
            ->published()
            ->create();

        $result = $this->parseAntlers(
            '{{ meerkat:comments thread="select-thread" select="id|comment_text|totally_unknown_column" }}'
            .'[{{ comment }}]{{ children }}[{{ comment }}]{{ /children }}{{ /meerkat:comments }}',
        );

        $this->assertStringContainsString('[Root body]', $result);
        $this->assertStringContainsString('[Child body]', $result);
    }
}
