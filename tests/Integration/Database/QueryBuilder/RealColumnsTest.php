<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Database\QueryBuilder;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\CommentQueryBuilder;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class RealColumnsTest extends TestCase
{
    #[Test]
    public function where_field_matches_tombstoned_comments_via_the_real_column(): void
    {
        $removed = CommentFactory::new()->removed('cleanup', 'admin')->create();
        CommentFactory::new()->create();

        $results = Comments::query()->whereField('is_removed', true)->get();

        $this->assertSame([$removed->id], $results->pluck('id')->all());
    }

    #[Test]
    public function where_field_matches_real_removal_metadata_columns(): void
    {
        $removed = CommentFactory::new()->removed('cleanup', 'admin')->create();
        CommentFactory::new()->create();

        $results = Comments::query()->whereField('removed_by', 'admin')->get();

        $this->assertSame([$removed->id], $results->pluck('id')->all());
    }

    #[Test]
    public function the_columns_list_covers_every_real_comments_column(): void
    {
        $schema = (new Comment)->getConnection()->getSchemaBuilder()->getColumnListing('comments');

        $this->assertSame([], array_values(array_diff($schema, CommentQueryBuilder::COLUMNS)));
    }
}
