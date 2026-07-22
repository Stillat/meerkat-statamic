<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Comments;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class CascadeDeleteTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.meerkat', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'meerkat_',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('meerkat.revisions.enabled', true);
    }

    #[Test]
    public function deleting_a_comment_via_raw_sql_cascades_to_revisions(): void
    {
        $comment = CommentFactory::new()
            ->threadId('cascade-rev')
            ->author('A', 'a@e.com')
            ->text('body')
            ->data(['comment' => 'body'])
            ->published()
            ->create();

        $this->assertGreaterThan(0, CommentRevision::query()->where('comment_id', $comment->id)->count());

        DB::connection('meerkat')->table('comments')->where('id', $comment->id)->delete();

        $this->assertSame(0, CommentRevision::query()->where('comment_id', $comment->id)->count());
    }

    #[Test]
    public function deleting_a_comment_via_raw_sql_cascades_to_moderation_audits(): void
    {
        $comment = CommentFactory::new()
            ->threadId('cascade-audit')
            ->author('A', 'a@e.com')
            ->text('body')
            ->data(['comment' => 'body'])
            ->published()
            ->create();

        CommentModerationAudit::create([
            'comment_id' => $comment->id,
            'actor_id' => 'mod-1',
            'action' => 'published',
            'details' => [],
        ]);

        $this->assertSame(1, CommentModerationAudit::query()->where('comment_id', $comment->id)->count());

        DB::connection('meerkat')->table('comments')->where('id', $comment->id)->delete();

        $this->assertSame(0, CommentModerationAudit::query()->where('comment_id', $comment->id)->count());
    }
}
