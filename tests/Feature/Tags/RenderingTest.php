<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Tags;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class RenderingTest extends TestCase
{
    #[Test]
    public function comments_tag_exposes_canonical_content_and_author_metadata(): void
    {
        $this->createEntry(['id' => 'render-basic']);
        CommentFactory::new()->threadId('render-basic')->author('John Doe', 'john@example.com')->text('Rendered body')->data(['comment' => 'Rendered body'])->published()->create();

        $result = $this->parseAntlers('{{ meerkat:comments thread="render-basic" }}[{{ name }}|{{ email }}|{{ comment }}|{{ is_published }}]{{ /meerkat:comments }}');

        $this->assertStringContainsString('[John Doe|john@example.com|Rendered body|1]', $result);
    }

    #[Test]
    public function children_render_recursively_with_their_materialized_depths(): void
    {
        $this->createEntry(['id' => 'render-nested']);
        $parents = [CommentFactory::new()->threadId('render-nested')->text('Depth 0')->data(['comment' => 'Depth 0'])->depth(0)->published()->create()];
        for ($depth = 1; $depth <= 3; $depth++) {
            $parents[] = CommentFactory::new()
                ->threadId('render-nested')
                ->parent($parents[$depth - 1]->id)
                ->depth($depth)
                ->text('Depth '.$depth)
                ->data(['comment' => 'Depth '.$depth])
                ->published()
                ->create();
        }

        $result = $this->parseAntlers(<<<'ANTLERS'
{{ meerkat:comments thread="render-nested" }}
[{{ depth }}:{{ comment }}]
{{ children }}[{{ depth }}:{{ comment }}]
{{ children }}[{{ depth }}:{{ comment }}]
{{ children }}[{{ depth }}:{{ comment }}]{{ /children }}
{{ /children }}{{ /children }}{{ /meerkat:comments }}
ANTLERS);

        foreach (range(0, 3) as $depth) {
            $this->assertStringContainsString("[{$depth}:Depth {$depth}]", $result);
        }
    }

    #[Test]
    public function public_rendering_ignores_include_unpublished_and_hides_spam(): void
    {
        $this->createEntry(['id' => 'render-public']);
        CommentFactory::new()->threadId('render-public')->text('Published')->data(['comment' => 'Published'])->published()->create();
        CommentFactory::new()->threadId('render-public')->text('Pending')->data(['comment' => 'Pending'])->pending()->create();
        CommentFactory::new()->threadId('render-public')->text('Spam')->data(['comment' => 'Spam'])->published()->spam()->create();

        $result = $this->parseAntlers('{{ meerkat:comments thread="render-public" include_unpublished="true" }}[{{ comment }}]{{ /meerkat:comments }}');

        $this->assertStringContainsString('[Published]', $result);
        $this->assertStringNotContainsString('Pending', $result);
        $this->assertStringNotContainsString('Spam', $result);
    }

    #[Test]
    public function moderators_can_include_every_moderation_state_and_nested_reply(): void
    {
        $this->createEntry(['id' => 'render-moderation']);
        $parent = CommentFactory::new()->threadId('render-moderation')->text('Published')->data(['comment' => 'Published'])->published()->create();
        CommentFactory::new()->threadId('render-moderation')->text('Pending')->data(['comment' => 'Pending'])->pending()->create();
        CommentFactory::new()->threadId('render-moderation')->text('Rejected')->data(['comment' => 'Rejected'])->rejected('policy')->create();
        CommentFactory::new()->threadId('render-moderation')->text('Spam')->data(['comment' => 'Spam'])->spam()->create();
        CommentFactory::new()->threadId('render-moderation')->parent($parent->id)->depth(1)->text('Pending child')->data(['comment' => 'Pending child'])->pending()->create();
        $this->actingAs($this->userWithPermissions('view comments', 'view blog entries', 'access default site'));

        $result = $this->parseAntlers('{{ meerkat:comments thread="render-moderation" include_unpublished="true" }}[{{ comment }}]{{ children }}[{{ comment }}]{{ /children }}{{ /meerkat:comments }}');

        foreach (['Published', 'Pending', 'Rejected', 'Spam', 'Pending child'] as $body) {
            $this->assertStringContainsString($body, $result);
        }
    }

    #[Test]
    public function empty_thread_renders_no_rows(): void
    {
        $this->createEntry(['id' => 'render-empty']);

        $this->assertSame('', trim($this->parseAntlers('{{ meerkat:comments thread="render-empty" }}{{ comment }}{{ /meerkat:comments }}')));
    }

    #[Test]
    public function form_fields_exclude_internal_and_configured_handles(): void
    {
        $this->createEntry(['id' => 'render-form-fields']);
        $template = '{{ meerkat:form thread="render-form-fields" }}{{ fields }}{{ field }}{{ /fields }}{{ /meerkat:form }}';
        $defaults = $this->parseAntlers($template);

        foreach (['created_at', 'is_spam', 'is_published', 'thread_id', 'author_id', 'collection', 'site'] as $excluded) {
            $this->assertStringNotContainsString('name="'.$excluded.'"', $defaults);
        }
        foreach (['comment', 'name', 'email', 'website'] as $included) {
            $this->assertStringContainsString('name="'.$included.'"', $defaults);
        }

        config(['meerkat.fields.exclude' => ['name', 'website']]);
        $custom = $this->parseAntlers($template);
        $this->assertStringNotContainsString('name="name"', $custom);
        $this->assertStringNotContainsString('name="website"', $custom);
        $this->assertStringContainsString('name="comment"', $custom);
        $this->assertStringContainsString('name="email"', $custom);
    }
}
