<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class FacadeHelpersTest extends TestCase
{
    #[Test]
    public function root_pagination_filters_unpublished_rows_and_reports_totals(): void
    {
        $published = CommentFactory::new()->count(3, [
            'thread_id' => 'roots',
            'parent_id' => null,
            'is_published' => true,
        ]);
        CommentFactory::new()->count(2, [
            'thread_id' => 'roots',
            'parent_id' => null,
            'is_published' => false,
        ]);

        $roots = Comments::rootsForThread('roots', 2);

        $this->assertSame(3, $roots->total());
        $this->assertCount(2, $roots);
        $this->assertEqualsCanonicalizing(
            collect($published)->pluck('id')->take(2)->all(),
            $roots->pluck('id')->all(),
        );
    }

    #[Test]
    public function user_history_resolves_guest_email_and_author_id_and_honors_limits(): void
    {
        $guest = CommentFactory::new()->count(3, ['author_email' => 'member@example.com']);
        CommentFactory::new()->count(2, ['author_email' => 'other@example.com']);
        $authenticated = CommentFactory::new()->count(2, ['author_id' => 123]);

        $this->assertEqualsCanonicalizing(
            collect($guest)->pluck('id')->all(),
            collect(Comments::userHistory('member@example.com'))->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            collect($authenticated)->pluck('id')->all(),
            collect(Comments::userHistory(123))->pluck('id')->all(),
        );
        $this->assertCount(2, Comments::userHistory('member@example.com', 2));
    }

    #[Test]
    public function recent_activity_applies_visibility_order_and_limit(): void
    {
        $older = CommentFactory::new()->published()->create(['created_at' => now()->subDays(2)]);
        $newer = CommentFactory::new()->published()->create(['created_at' => now()]);
        $unpublished = CommentFactory::new()->unpublished()->create(['created_at' => now()->addMinute()]);

        $public = collect(Comments::recentActivity(10));
        $all = collect(Comments::recentActivity(2, false));

        $this->assertSame([$newer->id, $older->id], $public->pluck('id')->all());
        $this->assertSame([$unpublished->id, $newer->id], $all->pluck('id')->all());
    }

    #[Test]
    public function moderation_and_spam_queues_select_and_order_their_states(): void
    {
        $oldPending = CommentFactory::new()->unpublished()->create([
            'is_spam' => false,
            'created_at' => now()->subDays(2),
        ]);
        $newPending = CommentFactory::new()->unpublished()->create([
            'is_spam' => false,
            'created_at' => now(),
        ]);
        $spam = CommentFactory::new()->spam()->create(['created_at' => now()->addMinute()]);
        CommentFactory::new()->published()->create(['is_spam' => false]);

        $this->assertSame(
            [$oldPending->id, $newPending->id],
            collect(Comments::moderationQueue())->pluck('id')->all(),
        );
        $this->assertSame([$spam->id], collect(Comments::spamQueue())->pluck('id')->all());
    }

    #[Test]
    public function ancestry_returns_the_chain_from_root_to_parent(): void
    {
        $root = CommentFactory::new()->create(['thread_id' => 'ancestry', 'parent_id' => null]);
        $child = CommentFactory::new()->create(['thread_id' => 'ancestry', 'parent_id' => $root->id]);
        $grandchild = CommentFactory::new()->create(['thread_id' => 'ancestry', 'parent_id' => $child->id]);

        $resolved = $this->requireValue(Comments::withAncestry($grandchild->id));

        $this->assertSame($grandchild->id, $resolved->id);
        $this->assertSame([$root->id, $child->id], collect($resolved->ancestors)->pluck('id')->all());
        $this->assertSame([], $this->requireValue(Comments::withAncestry($root->id))->ancestors);
        $this->assertNull(Comments::withAncestry(999999));
    }

    #[Test]
    public function search_matches_body_name_and_email_and_orders_newest_first(): void
    {
        $body = CommentFactory::new()->create([
            'comment_text' => 'Laravel body',
            'created_at' => now()->subDays(3),
        ]);
        $name = CommentFactory::new()->create([
            'comment_text' => 'Other',
            'author_name' => 'Laravel Person',
            'created_at' => now()->subDays(2),
        ]);
        $email = CommentFactory::new()->create([
            'comment_text' => 'Other',
            'author_email' => 'person@laravel.example',
            'created_at' => now(),
        ]);
        CommentFactory::new()->create(['comment_text' => 'Unrelated']);

        $results = collect(Comments::search('laravel'));

        $this->assertSame([$email->id, $name->id, $body->id], $results->pluck('id')->all());
        $this->assertSame([$email->id, $name->id], collect(Comments::search('laravel', 2))->pluck('id')->all());
        $this->assertSame([], Comments::search('not-present'));
    }
}
