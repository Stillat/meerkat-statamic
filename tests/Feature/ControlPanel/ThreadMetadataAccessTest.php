<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\ControlPanel;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ThreadMetadataAccessTest extends TestCase
{
    #[Test]
    public function thread_metadata_is_denied_outside_the_accessible_scope(): void
    {
        $this->makeBlogAndDocsCollections();
        $this->makeComment('docs-thread', 'docs', 'Docs comment');

        $this->actingAs($this->userWithPermissions('view comments', 'view blog entries', 'access default site'));

        $response = $this->getJson(cp_route('meerkat.comments.thread', ['threadId' => 'docs-thread']));

        $response->assertForbidden();
        $this->assertNull($response->json('thread'));
    }

    #[Test]
    public function thread_metadata_is_returned_inside_the_accessible_scope(): void
    {
        $this->makeBlogAndDocsCollections();
        $this->makeComment('blog-thread', 'blog', 'Blog comment');

        $this->actingAs($this->userWithPermissions('view comments', 'view blog entries', 'access default site'));

        $response = $this->getJson(cp_route('meerkat.comments.thread', ['threadId' => 'blog-thread']))
            ->assertOk();

        $this->assertSame('blog-thread', $response->json('thread.id'));
        $this->assertCount(1, $this->requireList($response->json('comments')));
    }

    private function makeBlogAndDocsCollections(): void
    {
        $blog = $this->makeStatamicCollection('blog');
        $blog->title('Blog');
        $blog->save();
        $docs = $this->makeStatamicCollection('docs');
        $docs->title('Docs');
        $docs->save();
    }

    private function makeComment(string $thread, string $collection, string $text): Comment
    {
        return CommentFactory::new()
            ->threadId($thread)
            ->collection($collection)
            ->site('default')
            ->author('Author', 'author@example.com')
            ->text($text)
            ->data(['comment' => $text])
            ->published()
            ->create();
    }
}
