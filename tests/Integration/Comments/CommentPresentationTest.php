<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Comments\CommentNode;
use Stillat\Meerkat\Comments\ThreadBuilder;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Events\CommentSaved;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class CommentPresentationTest extends TestCase
{
    #[Test]
    public function gravatar_url_is_derived_from_the_email_hash(): void
    {
        $comment = CommentFactory::new()->author('Jo', 'jo@example.com')->create();

        $expectedHash = md5('jo@example.com');

        $this->assertStringContainsString($expectedHash, $this->requireValue($comment->fresh())->gravatarUrl());
        $this->assertStringStartsWith('https://www.gravatar.com/avatar/', $comment->gravatarUrl());
    }

    #[Test]
    public function public_email_is_null_for_an_anonymous_author(): void
    {
        $anon = CommentFactory::new()->create(['author_email' => null, 'author_id' => null]);
        $known = CommentFactory::new()->author('Jo', 'jo@example.com')->create();

        $this->assertNull($this->requireValue($anon->fresh())->publicEmail());
        $this->assertSame('jo@example.com', $this->requireValue($known->fresh())->publicEmail());
    }

    #[Test]
    public function thread_builder_exposes_gravatar_and_permalink_conveniences(): void
    {
        $comment = CommentFactory::new()
            ->author('Jo', 'jo@example.com')
            ->text('hi')
            ->data(['comment' => 'hi'])
            ->published()
            ->create();

        $built = (new ThreadBuilder)->build([
            $this->requireValue(Comment::query()->find($comment->id)),
        ])->first();

        $this->assertInstanceOf(CommentNode::class, $built);
        $this->assertSame('comment-'.$comment->id, $built->anchor);
        $this->assertSame('#comment-'.$comment->id, $built->permalink);
        $this->assertNotEmpty($built->gravatar);

        $array = $built->toArray();
        $this->assertArrayHasKey('gravatar', $array);
        $this->assertSame('comment-'.$comment->id, $array['anchor']);
        $this->assertSame('#comment-'.$comment->id, $array['permalink']);
    }

    #[Test]
    public function comment_saved_fires_once_for_creates_and_once_for_later_saves(): void
    {
        Event::fake([CommentSaved::class]);

        $comment = CommentFactory::new()->text('Original')->create();

        Event::assertDispatchedTimes(CommentSaved::class, 1);

        Event::fake([CommentSaved::class]);

        $comment->comment_text = 'Edited';
        $comment->save();

        Event::assertDispatchedTimes(CommentSaved::class, 1);
    }

    #[Test]
    public function child_relations_are_ordered_oldest_first_with_an_id_tiebreaker(): void
    {
        $root = CommentFactory::new()->create();
        $newer = CommentFactory::new()->parent($root->id)->create([
            'created_at' => Carbon::parse('2026-01-03 12:00:00'),
        ]);
        $older = CommentFactory::new()->parent($root->id)->create([
            'created_at' => Carbon::parse('2026-01-01 12:00:00'),
        ]);
        $firstTie = CommentFactory::new()->parent($root->id)->create([
            'created_at' => Carbon::parse('2026-01-02 12:00:00'),
        ]);
        $secondTie = CommentFactory::new()->parent($root->id)->create([
            'created_at' => Carbon::parse('2026-01-02 12:00:00'),
        ]);

        $this->assertSame(
            [$older->id, $firstTie->id, $secondTie->id, $newer->id],
            $this->requireValue($root->fresh())->children->pluck('id')->all(),
        );
    }
}
