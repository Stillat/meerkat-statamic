<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Tags;

use Illuminate\Support\Carbon;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\ThreadMetric;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class AdditionalTagsTest extends TestCase
{
    #[Test]
    public function recent_comments_enforces_order_limit_and_public_visibility(): void
    {
        $this->createEntry(['id' => 'recent-entry']);

        foreach ([
            ['Oldest', 4, true],
            ['Second', 2, true],
            ['Newest', 1, true],
            ['Hidden', 0, false],
        ] as [$text, $daysAgo, $published]) {
            CommentFactory::new()
                ->threadId('recent-entry')
                ->author($text, strtolower($text).'@example.com')
                ->text($text)
                ->data(['comment' => $text])
                ->published($published)
                ->create(['created_at' => Carbon::now()->subDays($daysAgo)]);
        }

        $result = $this->parseAntlers(
            '{{ meerkat:recent_comments limit="2" }}[{{ comment_text }}]{{ /meerkat:recent_comments }}',
        );

        $newest = strpos($result, '[Newest]');
        $second = strpos($result, '[Second]');
        $this->assertNotFalse($newest);
        $this->assertNotFalse($second);
        $this->assertLessThan($second, $newest);
        $this->assertSame(2, substr_count($result, '['));
        $this->assertStringNotContainsString('Oldest', $result);
        $this->assertStringNotContainsString('Hidden', $result);
    }

    #[Test]
    public function feedback_tags_expose_success_state_and_validation_errors(): void
    {
        session()->flash('meerkat.success', 'Comment submitted successfully.');
        session()->flash('meerkat.submission_created', true);
        session()->flash('errors', (new ViewErrorBag)->put(
            'meerkat',
            new MessageBag(['comment' => ['Please write a comment.']]),
        ));

        $success = $this->parseAntlers(
            '{{ meerkat:success }}[{{ message }}|created:{{ submission_created }}]{{ /meerkat:success }}',
        );
        $errors = $this->parseAntlers(
            '{{ meerkat:errors }}count:{{ count }}{{ messages }}[{{ value }}]{{ /messages }}{{ /meerkat:errors }}',
        );

        $this->assertStringContainsString('[Comment submitted successfully.|created:1]', $success);
        $this->assertStringContainsString('count:1', $errors);
        $this->assertStringContainsString('[Please write a comment.]', $errors);
    }

    #[Test]
    public function public_helper_tags_exclude_spam_and_tombstoned_subtrees(): void
    {
        $this->createEntry(['id' => 'safe-helper-entry']);
        CommentFactory::new()->threadId('safe-helper-entry')->author('A', 'a@example.com')->text('visible')->data(['comment' => 'visible'])->published()->create();
        CommentFactory::new()->threadId('safe-helper-entry')->author('Bot', 'bot@example.com')->text('spam')->data(['comment' => 'spam'])->published()->spam()->create();
        $removed = CommentFactory::new()->threadId('safe-helper-entry')->author('Gone', 'gone@example.com')->text('removed')->data(['comment' => 'removed'])->published()->create();
        CommentFactory::new()
            ->threadId('safe-helper-entry')
            ->author('Child', 'child@example.com')
            ->text('removed child')
            ->data(['comment' => 'removed child'])
            ->parent($removed->id)
            ->depth(1)
            ->published()
            ->create();
        Comments::deleteComment($removed->id);

        $count = trim($this->parseAntlers('{{ meerkat:comment_count thread="safe-helper-entry" }}'));
        $recent = $this->parseAntlers('{{ meerkat:recent_comments limit="10" }}[{{ comment_text }}]{{ /meerkat:recent_comments }}');
        $author = $this->parseAntlers('{{ meerkat:author_history identifier="child@example.com" }}[{{ comment_text }}]{{ /meerkat:author_history }}');
        $top = $this->parseAntlers('{{ meerkat:top_threads limit="5" }}[{{ thread_id }}:{{ comment_count }}]{{ /meerkat:top_threads }}');

        $this->assertSame('1', $count);
        $this->assertStringContainsString('[visible]', $recent);
        $this->assertStringNotContainsString('[spam]', $recent);
        $this->assertStringNotContainsString('[removed]', $recent);
        $this->assertStringNotContainsString('[removed child]', $recent);
        $this->assertStringNotContainsString('[removed child]', $author);
        $this->assertStringContainsString('[safe-helper-entry:1]', $top);
    }

    #[Test]
    public function top_threads_backfills_hidden_candidates_and_orders_visible_results(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $root = CommentFactory::new()
                ->threadId("hidden-top-{$i}")
                ->author('Removed', "removed{$i}@example.com")
                ->text('removed root')
                ->data(['comment' => 'removed root'])
                ->published()
                ->create();

            for ($j = 0; $j < 3; $j++) {
                CommentFactory::new()
                    ->threadId("hidden-top-{$i}")
                    ->parent($root->id)
                    ->depth(1)
                    ->author('Child', "child{$i}{$j}@example.com")
                    ->text('hidden child')
                    ->data(['comment' => 'hidden child'])
                    ->published()
                    ->create();
            }

            Comments::deleteComment($root->id);
        }

        for ($i = 0; $i < 3; $i++) {
            CommentFactory::new()->threadId('visible-busy')->author("A{$i}", "a{$i}@example.com")->text('busy')->data(['comment' => 'busy'])->published()->create();
        }
        CommentFactory::new()->threadId('visible-quiet')->author('B', 'b@example.com')->text('quiet')->data(['comment' => 'quiet'])->published()->create();

        $result = $this->parseAntlers(
            '{{ meerkat:top_threads limit="2" }}[{{ thread_id }}:{{ comment_count }}]{{ /meerkat:top_threads }}',
        );

        $busy = strpos($result, '[visible-busy:3]');
        $quiet = strpos($result, '[visible-quiet:1]');
        $this->assertNotFalse($busy);
        $this->assertNotFalse($quiet);
        $this->assertLessThan($quiet, $busy);
        $this->assertStringNotContainsString('hidden-top-', $result);
    }

    #[Test]
    public function author_history_resolves_explicit_and_authenticated_identities_without_leaking_anonymous_history(): void
    {
        $this->createEntry(['id' => 'history-entry']);
        CommentFactory::new()->threadId('history-entry')->author('John', 'john@example.com')->text('John comment')->data(['comment' => 'John comment'])->published()->create();
        CommentFactory::new()->threadId('history-entry')->author('Other', 'other@example.com')->text('Other comment')->data(['comment' => 'Other comment'])->published()->create();

        $explicit = $this->parseAntlers('{{ meerkat:author_history identifier="john@example.com" }}[{{ comment_text }}]{{ /meerkat:author_history }}');
        $anonymous = $this->parseAntlers('{{ meerkat:author_history }}[{{ comment_text }}]{{ /meerkat:author_history }}');

        $user = $this->makeStatamicUser();
        $user->id('history-user');
        $user->email('john@example.com');
        $user->save();
        $this->actingAs($user);
        $authenticated = $this->parseAntlers('{{ meerkat:author_history }}[{{ comment_text }}]{{ /meerkat:author_history }}');

        $this->assertStringContainsString('[John comment]', $explicit);
        $this->assertStringNotContainsString('[Other comment]', $explicit);
        $this->assertStringNotContainsString('comment]', $anonymous);
        $this->assertStringContainsString('[John comment]', $authenticated);
        $this->assertStringNotContainsString('[Other comment]', $authenticated);
    }

    #[Test]
    public function thread_stats_separate_public_counts_from_scoped_moderation_metrics(): void
    {
        $this->createEntry(['id' => 'stats-entry']);
        CommentFactory::new()->threadId('stats-entry')->author('A', 'a@example.com')->text('a')->data(['comment' => 'a'])->published()->create();
        CommentFactory::new()->threadId('stats-entry')->author('B', 'b@example.com')->text('b')->data(['comment' => 'b'])->published()->create();
        CommentFactory::new()->threadId('stats-entry')->author('C', 'c@example.com')->text('c')->data(['comment' => 'c'])->unpublished()->create();

        ThreadMetric::query()->updateOrCreate(['thread_id' => 'stats-entry'], [
            'site' => 'default',
            'collection' => 'blog',
            'total_comments' => 77,
            'published_comments' => 66,
            'pending_comments' => 11,
            'spam_comments' => 0,
            'root_comments' => 77,
            'reply_comments' => 0,
            'participants' => 12,
            'max_depth' => 0,
        ]);

        $template = '{{ meerkat:thread_stats thread="stats-entry" include_moderation="true" }}total={{ total_comments }} published={{ published_comments }}{{ /meerkat:thread_stats }}';
        $public = $this->parseAntlers($template);

        $this->actingAs($this->userWithPermissions('view comments', 'view blog entries'));
        $moderator = $this->parseAntlers($template);

        $this->assertStringContainsString('total=2 published=2', $public);
        $this->assertStringContainsString('total=77 published=66', $moderator);
    }
}
