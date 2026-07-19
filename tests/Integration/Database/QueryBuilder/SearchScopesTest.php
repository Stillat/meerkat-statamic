<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Database\QueryBuilder;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class SearchScopesTest extends TestCase
{
    protected string $threadId = 'test-thread';

    #[Test]
    public function it_searches_author_name_case_insensitively(): void
    {
        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'author_name' => 'John Doe',
        ]);

        $results = Comments::query()
            ->forThread($this->threadId)
            ->where('name', 'like', '%john%')
            ->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function it_rejects_an_unknown_operator_on_a_dynamic_column(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Comments::query()
            ->forThread($this->threadId)
            ->where('email', '); drop table comments;--', 'x')
            ->get();
    }

    #[Test]
    public function it_searches_in_comment_text(): void
    {
        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'comment_text' => 'This is a great article!',
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'comment_text' => 'I disagree with this.',
        ]);

        $results = Comments::query()
            ->forThread($this->threadId)
            ->search('great')
            ->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function it_searches_across_all_fields(): void
    {
        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'comment_text' => 'This is great!',
            'author_name' => 'John Doe',
            'author_email' => 'john@example.com',
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'comment_text' => 'Another comment',
            'author_name' => 'Jane Smith',
            'author_email' => 'jane@example.com',
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'comment_text' => 'Third comment',
            'author_name' => 'Bob Johnson',
            'author_email' => 'bob@example.com',
        ]);

        $textResults = Comments::query()
            ->forThread($this->threadId)
            ->searchAll('great')
            ->get();
        $this->assertCount(1, $textResults);

        $nameResults = Comments::query()
            ->forThread($this->threadId)
            ->searchAll('Jane')
            ->get();
        $this->assertCount(1, $nameResults);

        $emailResults = Comments::query()
            ->forThread($this->threadId)
            ->searchAll('bob@')
            ->get();
        $this->assertCount(1, $emailResults);
    }

    #[Test]
    public function it_checks_if_comment_has_specific_field(): void
    {
        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'comment_data' => ['rating' => 5],
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'comment_data' => ['feedback' => 'Great!'],
        ]);

        $withRating = Comments::query()
            ->forThread($this->threadId)
            ->hasField('rating')
            ->get();

        $this->assertCount(1, $withRating);
    }

    #[Test]
    public function it_queries_specific_field_in_comment_data(): void
    {
        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'comment_data' => ['rating' => 5],
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'comment_data' => ['rating' => 3],
        ]);

        $highRated = Comments::query()
            ->forThread($this->threadId)
            ->whereField('rating', '>=', 5)
            ->get();

        $this->assertCount(1, $highRated);
    }
}
