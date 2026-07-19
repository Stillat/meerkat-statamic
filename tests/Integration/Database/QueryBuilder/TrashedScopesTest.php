<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Database\QueryBuilder;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class TrashedScopesTest extends TestCase
{
    #[Test]
    public function trash_scopes_partition_live_and_soft_deleted_comments_and_remain_chainable(): void
    {
        $live = CommentFactory::new()->create(['thread_id' => 'trash']);
        $deleted = CommentFactory::new()->create(['thread_id' => 'trash']);
        $deleted->delete();

        $query = fn () => Comments::query()->forThread('trash');

        $this->assertSame([$live->id], $query()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$live->id, $deleted->id], $query()->withTrashed()->pluck('id')->all());
        $this->assertSame([$live->id], $query()->withTrashed(false)->pluck('id')->all());
        $this->assertSame([$deleted->id], $query()->onlyTrashed()->pluck('id')->all());
        $this->assertSame([$live->id], $query()->withoutTrashed()->pluck('id')->all());

        $builder = $query();
        $this->assertSame($builder, $builder->withTrashed());
        $this->assertSame($builder, $builder->withoutTrashed());
        $this->assertSame($builder, $builder->onlyTrashed());
    }
}
