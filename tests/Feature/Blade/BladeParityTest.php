<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Blade;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class BladeParityTest extends TestCase
{
    #[Test]
    public function comments_component_adapts_hierarchy_visibility_html_pagination_and_empty_state(): void
    {
        $this->createEntry(['id' => 'blade-comments']);
        $this->createEntry(['id' => 'blade-empty']);
        $parent = CommentFactory::new()->threadId('blade-comments')->author('Parent')->text('Hello **world**')->data(['comment' => 'Hello **world**'])->depth(0)->published()->create();
        CommentFactory::new()->threadId('blade-comments')->author('Child')->text('child')->data(['comment' => 'child'])->parent($parent->id)->depth(1)->published()->create();
        CommentFactory::new()->threadId('blade-comments')->text('Hidden')->data(['comment' => 'Hidden'])->unpublished()->create();
        for ($i = 1; $i <= 4; $i++) {
            CommentFactory::new()->threadId('blade-comments')->text('Root '.$i)->data(['comment' => 'Root '.$i])->published()->create();
        }

        $comments = $this->render(<<<'BLADE'
        <s-meerkat:comments thread="blade-comments">
        [{{ $depth }}:{{ $author_name }}:{!! $comment_html !!}:{{ $has_replies ? 'replies' : 'leaf' }}]
        @foreach ($children as $child)[{{ $child['depth'] }}:{{ $child['author_name'] }}]@endforeach
        </s-meerkat:comments>
        BLADE);
        $pagination = $this->render('<s-meerkat:comments thread="blade-comments" paginate="2">total={{ $paginate[\'total_items\'] }} pages={{ $paginate[\'total_pages\'] }}</s-meerkat:comments>');
        $empty = $this->render('<s-meerkat:comments thread="blade-empty"><s-no_results>EMPTY</s-no_results></s-meerkat:comments>');

        $this->assertStringContainsString('[0:Parent:<p>Hello <strong>world</strong></p>:replies]', $comments);
        $this->assertStringContainsString('[1:Child]', $comments);
        $this->assertStringNotContainsString('Hidden', $comments);
        $this->assertStringContainsString('total=5 pages=3', $pagination);
        $this->assertStringContainsString('EMPTY', $empty);
    }

    #[Test]
    public function form_and_comments_enabled_components_adapt_submission_and_open_state_contracts(): void
    {
        $this->createEntry(['id' => 'blade-open']);
        $this->createEntry(['id' => 'blade-closed', 'slug' => 'blade-closed', 'comments_closed' => true]);

        $form = $this->render(<<<'BLADE'
<s-meerkat:form thread="blade-open">
    @foreach ($fields as $field){!! $field['field'] !!}@endforeach
    <input name="{{ $honeypot }}">
</s-meerkat:form>
BLADE);
        $enabled = $this->render(<<<'BLADE'
OPEN[<s-meerkat:comments_enabled thread="blade-open">FORM</s-meerkat:comments_enabled>]
CLOSED[<s-meerkat:comments_enabled thread="blade-closed">FORM</s-meerkat:comments_enabled>]
BLADE);

        foreach (['<form', 'name="comment"', 'name="username"', '_meerkat_context', '_meerkat_context_signature'] as $contract) {
            $this->assertStringContainsString($contract, $form);
        }
        $this->assertStringContainsString('OPEN[FORM]', str_replace("\n", '', $enabled));
        $this->assertStringContainsString('CLOSED[]', str_replace("\n", '', $enabled));
    }

    #[Test]
    public function privileged_recent_and_stats_components_expose_their_adapter_payloads(): void
    {
        $admin = $this->makeStatamicUser();
        $admin->id('blade-admin');
        $admin->email('blade@example.com');
        $admin->makeSuper();
        $admin->save();
        $this->actingAs($admin);
        $this->createEntry(['id' => 'blade-metrics']);
        CommentFactory::new()->threadId('blade-metrics')->author('Recent Author')->text('one')->data(['comment' => 'one'])->published()->create();
        CommentFactory::new()->threadId('blade-metrics')->author('Removed')->text('removed')->data(['comment' => 'removed'])->published()->removed('moderated')->create();

        $tombstone = $this->render(<<<'BLADE'
<s-meerkat:comments thread="blade-metrics" include_tombstones="true">
    {{ $current_user['is_authenticated'] ? 'AUTH' : 'GUEST' }}
    @if ($is_removed)TOMBSTONE @endif
</s-meerkat:comments>
BLADE);
        $recent = $this->render('<s-meerkat:recent_comments limit="5">{{ $author_name }}</s-meerkat:recent_comments>');
        $stats = $this->render('<s-meerkat:thread_stats thread="blade-metrics">total={{ $total_comments }}</s-meerkat:thread_stats>');

        $this->assertStringContainsString('AUTH', $tombstone);
        $this->assertStringContainsString('TOMBSTONE', $tombstone);
        $this->assertStringContainsString('Recent Author', $recent);
        $this->assertStringContainsString('total=1', $stats);
    }

    private function render(string $template): string
    {
        return (string) Blade::render($template);
    }
}
