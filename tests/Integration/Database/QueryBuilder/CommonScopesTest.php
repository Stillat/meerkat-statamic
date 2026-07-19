<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Database\QueryBuilder;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\CommentQueryBuilder;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class CommonScopesTest extends TestCase
{
    #[Test]
    public function sorting_scopes_apply_their_documented_order(): void
    {
        $olderDeep = CommentFactory::new()->create([
            'thread_id' => 'sorting',
            'created_at' => now()->subDays(5),
            'visual_path' => '000002',
            'depth' => 2,
        ]);
        $newerShallow = CommentFactory::new()->create([
            'thread_id' => 'sorting',
            'created_at' => now(),
            'visual_path' => '000001',
            'depth' => 0,
        ]);

        $query = fn () => Comments::query()->forThread('sorting');

        $this->assertSame($newerShallow->id, $this->requireValue($query()->newest()->first())->id);
        $this->assertSame($olderDeep->id, $this->requireValue($query()->oldest()->first())->id);
        $this->assertSame($newerShallow->id, $this->requireValue($query()->hierarchical()->first())->id);
        $this->assertSame($newerShallow->id, $this->requireValue($query()->byDepth('asc')->first())->id);
        $this->assertSame($olderDeep->id, $this->requireValue($query()->byDepth('desc')->first())->id);
    }

    #[Test]
    public function status_scopes_select_the_expected_records(): void
    {
        $approved = CommentFactory::new()->create([
            'thread_id' => 'status',
            'is_published' => true,
            'is_spam' => false,
            'is_ham' => false,
        ]);
        $pending = CommentFactory::new()->create([
            'thread_id' => 'status',
            'is_published' => false,
            'is_spam' => false,
            'is_ham' => false,
        ]);
        $spam = CommentFactory::new()->create([
            'thread_id' => 'status',
            'is_published' => true,
            'is_spam' => true,
            'is_ham' => false,
        ]);
        $ham = CommentFactory::new()->create([
            'thread_id' => 'status',
            'is_published' => true,
            'is_spam' => false,
            'is_ham' => true,
        ]);

        $ids = fn (CommentQueryBuilder $query): array => $query->get()->pluck('id')->all();
        $query = fn () => Comments::query()->forThread('status');

        $this->assertEqualsCanonicalizing([$approved->id, $spam->id, $ham->id], $ids($query()->published()));
        $this->assertSame([$pending->id], $ids($query()->unpublished()));
        $this->assertSame([$spam->id], $ids($query()->spam()));
        $this->assertSame([$ham->id], $ids($query()->ham()));
        $this->assertSame([$pending->id], $ids($query()->pendingModeration()));
        $this->assertEqualsCanonicalizing([$approved->id, $ham->id], $ids($query()->approved()));
    }

    #[Test]
    public function ownership_scopes_select_threads_sites_and_collections(): void
    {
        $first = CommentFactory::new()->create([
            'thread_id' => 'thread-1',
            'site' => 'default',
            'collection' => 'posts',
        ]);
        $second = CommentFactory::new()->create([
            'thread_id' => 'thread-2',
            'site' => 'blog',
            'collection' => 'articles',
        ]);
        CommentFactory::new()->create([
            'thread_id' => 'thread-3',
            'site' => 'other',
            'collection' => 'docs',
        ]);

        $this->assertSame([$first->id], Comments::query()->forThread('thread-1')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            Comments::query()->forThreads(['thread-1', 'thread-2'])->pluck('id')->all(),
        );
        $this->assertSame([$first->id], Comments::query()->forSite('default')->pluck('id')->all());
        $this->assertSame([$first->id], Comments::query()->forCollection('posts')->pluck('id')->all());
    }

    #[Test]
    public function time_scopes_apply_their_documented_windows(): void
    {
        $today = CommentFactory::new()->create(['thread_id' => 'time', 'created_at' => now()]);
        $threeDaysAgo = CommentFactory::new()->create(['thread_id' => 'time', 'created_at' => now()->subDays(3)]);
        $tenDaysAgo = CommentFactory::new()->create(['thread_id' => 'time', 'created_at' => now()->subDays(10)]);
        CommentFactory::new()->create(['thread_id' => 'time', 'created_at' => now()->subDays(16)]);

        $ids = fn (CommentQueryBuilder $query): array => $query->get()->pluck('id')->all();
        $query = fn () => Comments::query()->forThread('time');

        $this->assertEqualsCanonicalizing([$today->id, $threeDaysAgo->id], $ids($query()->recent()));
        $this->assertEqualsCanonicalizing([$today->id, $threeDaysAgo->id, $tenDaysAgo->id], $ids($query()->recent(14)));
        $this->assertEqualsCanonicalizing([$today->id, $threeDaysAgo->id], $ids($query()->since(now()->subDays(5))));
        $this->assertSame([$tenDaysAgo->id], $ids($query()->between(now()->subDays(12), now()->subDays(5))));
        $this->assertSame([$today->id], $ids($query()->today()));
    }
}
