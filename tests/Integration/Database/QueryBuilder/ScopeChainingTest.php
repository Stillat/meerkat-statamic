<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Database\QueryBuilder;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ScopeChainingTest extends TestCase
{
    protected string $threadId = 'test-thread';

    #[Test]
    public function it_chains_multiple_scopes_together(): void
    {
        $root = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'is_published' => true,
            'depth' => 0,
            'parent_id' => null,
            'created_at' => now()->subDays(3),
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'is_published' => false,
            'depth' => 0,
            'parent_id' => null,
            'created_at' => now()->subDays(3),
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'is_published' => true,
            'depth' => 1,
            'parent_id' => $root->id,
            'created_at' => now()->subDays(3),
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'is_published' => true,
            'depth' => 0,
            'parent_id' => null,
            'created_at' => now()->subDays(10),
        ]);

        $results = Comments::query()
            ->forThread($this->threadId)
            ->published()
            ->roots()
            ->recent(7)
            ->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function where_not_between_preserves_the_and_boolean(): void
    {
        $included = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'is_published' => true,
            'depth' => 1,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'is_published' => true,
            'depth' => 3,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'is_published' => false,
            'depth' => 5,
        ]);

        $results = Comments::query()
            ->forThread($this->threadId)
            ->published()
            ->whereNotBetween('depth', [2, 4])
            ->get();

        $this->assertSame([$included->id], $results->pluck('id')->all());
    }

    #[Test]
    public function or_where_not_between_still_uses_the_or_boolean(): void
    {
        $first = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'depth' => 1,
        ]);

        $second = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'depth' => 3,
        ]);

        $third = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'depth' => 5,
        ]);

        $excluded = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'depth' => 4,
        ]);

        $results = Comments::query()
            ->where('comments.id', $second->id)
            ->orWhereNotBetween('depth', [2, 4])
            ->get();

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id, $third->id],
            $results->pluck('id')->all(),
        );
        $this->assertNotContains($excluded->id, $results->pluck('id')->all());
    }
}
