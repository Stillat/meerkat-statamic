<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Comments;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class RevisionsProGateTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('meerkat.revisions.enabled', true);
        $app['config']->set('statamic.editions.pro', false);
    }

    #[Test]
    public function revisions_are_neither_captured_nor_exposed_without_pro(): void
    {
        $this->createStatamicCollection('blog', 'Blog');
        $this->makeAdmin('nopro-admin', 'nopro@example.com');
        $comment = CommentFactory::new()->threadId('nopro-thread')->author('A', 'a@example.com')->text('original')->data(['comment' => 'original'])->published()->create();
        $comment->comment_text = 'edited';
        $comment->save();

        $this->assertSame(0, CommentRevision::query()->where('comment_id', $comment->id)->count());
        $this->getJson('/api/meerkat/comments/'.$comment->id.'/revisions')->assertNotFound();
    }
}
