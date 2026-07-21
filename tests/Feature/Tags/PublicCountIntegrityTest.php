<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Tags;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Support\CommentVisibility;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class PublicCountIntegrityTest extends TestCase
{
    #[Test]
    public function public_counts_exclude_replies_orphaned_by_an_unpublished_root(): void
    {
        $this->createEntry(['id' => 'orphan-count']);
        $root = CommentFactory::new()->threadId('orphan-count')->text('Root')->data(['comment' => 'Root'])->published()->create();

        for ($i = 0; $i < 5; $i++) {
            CommentFactory::new()->replyTo($root)->text('Reply '.$i)->data(['comment' => 'Reply '.$i])->published()->create();
        }

        $countTemplate = '{{ meerkat:comment_count thread="orphan-count" }}';
        $statsTemplate = '{{ meerkat:thread_stats thread="orphan-count" }}total={{ total_comments }}{{ /meerkat:thread_stats }}';

        $this->assertSame('6', trim($this->parseAntlers($countTemplate)));

        $root->is_published = false;
        $root->save();

        $this->assertSame('0', trim($this->parseAntlers($countTemplate)));
        $this->assertStringContainsString('total=0', $this->parseAntlers($statsTemplate));

        $recent = $this->parseAntlers('{{ meerkat:recent_comments limit="10" }}[{{ comment_text }}]{{ /meerkat:recent_comments }}');
        $this->assertStringNotContainsString('Reply', $recent);
    }

    #[Test]
    public function public_search_excludes_orphaned_subtrees(): void
    {
        $this->createEntry(['id' => 'orphan-search']);
        $root = CommentFactory::new()->threadId('orphan-search')->text('searchable-visible')->data(['comment' => 'searchable-visible'])->published()->create();
        $hiddenRoot = CommentFactory::new()->threadId('orphan-search')->text('hidden root')->data(['comment' => 'hidden root'])->unpublished()->create();
        CommentFactory::new()->replyTo($root)->text('searchable-live reply')->data(['comment' => 'searchable-live reply'])->published()->create();
        CommentFactory::new()->replyTo($hiddenRoot)->text('searchable-orphan reply')->data(['comment' => 'searchable-orphan reply'])->published()->create();

        $results = app(CommentVisibility::class)->publicSearch('searchable', 10);
        $texts = array_map(fn ($comment) => $comment->comment_text, $results);

        $this->assertContains('searchable-visible', $texts);
        $this->assertContains('searchable-live reply', $texts);
        $this->assertNotContains('searchable-orphan reply', $texts);
    }

    #[Test]
    public function top_threads_uses_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 30; $i++) {
            CommentFactory::new()->threadId('quiet-'.$i)->text('quiet')->data(['comment' => 'quiet'])->published()->create();
        }
        for ($i = 0; $i < 3; $i++) {
            CommentFactory::new()->threadId('busy')->author('A'.$i, 'a'.$i.'@example.com')->text('busy')->data(['comment' => 'busy'])->published()->create();
        }

        $connection = DB::connection('meerkat');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $rows = app(CommentVisibility::class)->topPublicThreads(3);

        $queries = count($connection->getQueryLog());
        $connection->disableQueryLog();

        $this->assertSame('busy', $rows[0]['thread_id']);
        $this->assertSame(3, $rows[0]['comment_count']);
        $this->assertSame(3, $rows[0]['participant_count']);
        $this->assertCount(3, $rows);
        $this->assertLessThan(10, $queries);
    }

    #[Test]
    public function moderator_comment_count_honors_tombstone_flags(): void
    {
        $this->createEntry(['id' => 'tombstone-count']);
        CommentFactory::new()->threadId('tombstone-count')->text('Published')->data(['comment' => 'Published'])->published()->create();
        CommentFactory::new()->threadId('tombstone-count')->text('Pending')->data(['comment' => 'Pending'])->pending()->create();
        $removed = CommentFactory::new()->threadId('tombstone-count')->text('Removed root')->data(['comment' => 'Removed root'])->published()->create();
        CommentFactory::new()->replyTo($removed)->text('Removed child')->data(['comment' => 'Removed child'])->published()->create();
        Comments::deleteComment($removed->id);

        $this->actingAs($this->userWithPermissions('view comments', 'view blog entries', 'access default site'));

        $base = '{{ meerkat:comment_count thread="tombstone-count" include_unpublished="true"';

        $this->assertSame('2', trim($this->parseAntlers($base.' }}')));
        $this->assertSame('3', trim($this->parseAntlers($base.' include_tombstones="true" }}')));
        $this->assertSame('4', trim($this->parseAntlers($base.' include_tombstones="true" include_tombstone_replies="true" }}')));
    }

    #[Test]
    public function comment_count_supports_a_thread_wildcard_as_an_aggregate_public_count(): void
    {
        CommentFactory::new()->threadId('wild-a')->text('a1')->data(['comment' => 'a1'])->published()->create();
        CommentFactory::new()->threadId('wild-a')->text('a2')->data(['comment' => 'a2'])->published()->create();
        CommentFactory::new()->threadId('wild-b')->text('b1')->data(['comment' => 'b1'])->published()->create();
        $hiddenRoot = CommentFactory::new()->threadId('wild-b')->text('hidden root')->data(['comment' => 'hidden root'])->unpublished()->create();
        CommentFactory::new()->replyTo($hiddenRoot)->text('orphaned')->data(['comment' => 'orphaned'])->published()->create();

        $this->assertSame('3', trim($this->parseAntlers('{{ meerkat:comment_count thread="*" }}')));
    }

    #[Test]
    public function thread_stats_and_comments_enabled_pin_explicit_wildcard_behavior(): void
    {
        CommentFactory::new()->threadId('wild-pin')->text('a')->data(['comment' => 'a'])->published()->create();

        $stats = $this->parseAntlers('{{ meerkat:thread_stats thread="*" }}[{{ total_comments }}]{{ /meerkat:thread_stats }}');
        $enabled = $this->parseAntlers('{{ if {meerkat:comments_enabled thread="*"} }}yes{{ else }}no{{ /if }}');

        $this->assertSame('[]', trim($stats));
        $this->assertSame('no', trim($enabled));
    }
}
