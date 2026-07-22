<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Api;

use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\ThreadMetric;
use Stillat\Meerkat\Http\Resources\Comments\PublicCommentResource;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class PublicEndpointsTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('meerkat.revisions.enabled', true);
    }

    #[Test]
    public function thread_endpoint_returns_thread_and_metric_payloads(): void
    {
        $this->seedThread('api-thread', 3);

        $response = $this->getJson('/api/meerkat/threads/api-thread')->assertOk();

        $response->assertJsonStructure(['thread', 'metrics']);
        $this->assertSame('api-thread', $response->json('thread.thread_id'));
    }

    #[Test]
    public function out_of_range_numeric_comment_ids_return_404_not_500(): void
    {
        $this->seedThread('api-overflow', 1);

        $this->getJson('/api/meerkat/threads/api-overflow/children/99999999999999999999')->assertNotFound();
        $this->getJson('/api/meerkat/threads/api-overflow/children/abc')->assertNotFound();
    }

    #[Test]
    public function comments_endpoint_returns_only_the_requested_threads_published_comments(): void
    {
        $expected = $this->seedThread('api-comments', 3);
        $this->seedThread('other-thread', 1);

        $response = $this->getJson('/api/meerkat/threads/api-comments/comments')->assertOk();

        $this->assertSame('api-comments', $response->json('thread_id'));
        $this->assertSame(
            array_map(fn ($comment) => $comment->id, $expected),
            array_column($this->requireRows($response->json('comments')), 'id'),
        );
    }

    #[Test]
    public function anonymous_comments_hide_published_spam(): void
    {
        $visible = CommentFactory::new()->threadId('api-spam')->author('Real', 'real@example.com')->text('legit')->data(['comment' => 'legit'])->published()->create();
        $spam = CommentFactory::new()->threadId('api-spam')->author('Bot', 'bot@example.com')->text('buy now')->data(['comment' => 'buy now'])->published()->spam()->create();

        $ids = array_column($this->requireRows($this->getJson('/api/meerkat/threads/api-spam/comments')->assertOk()->json('comments')), 'id');

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($spam->id, $ids);
    }

    #[Test]
    public function full_thread_cap_rejects_unbounded_reads_without_breaking_paginated_roots(): void
    {
        config()->set('meerkat.api.max_full_thread_comments', 2);
        $this->seedThread('api-capped', 3);

        $this->getJson('/api/meerkat/threads/api-capped/comments')
            ->assertStatus(413)
            ->assertJsonPath('max_comments', 2);
        $this->getJson('/api/meerkat/threads/api-capped/roots?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2);
    }

    #[Test]
    public function resource_projection_separates_public_fields_from_moderation_and_pii(): void
    {
        $this->seedThread('api-projection', 1);

        $public = $this->requireObject($this->getJson('/api/meerkat/threads/api-projection/comments')->assertOk()->json('comments.0'));
        foreach (['moderation_status', 'moderation_reason', 'moderation_notes', 'moderated_by', 'is_spam', 'checked_for_spam'] as $private) {
            $this->assertArrayNotHasKey($private, $public);
        }
        $publicAuthor = $this->requireObject($public['author']);
        $this->assertArrayNotHasKey('email', $publicAuthor);
        $this->assertArrayNotHasKey('id', $publicAuthor);

        $this->actAsModerator();
        $privileged = $this->requireObject($this->getJson('/api/meerkat/threads/api-projection/comments')->assertOk()->json('comments.0'));
        $privilegedAuthor = $this->requireObject($privileged['author']);
        $this->assertArrayHasKey('moderation_status', $privileged);
        $this->assertArrayHasKey('is_spam', $privileged);
        $this->assertArrayHasKey('email', $privilegedAuthor);
        $this->assertArrayHasKey('id', $privilegedAuthor);
    }

    #[Test]
    public function public_resource_hook_can_enrich_rows_and_receives_privilege_context(): void
    {
        $this->resetStatamicHooks();
        $this->seedThread('api-hook', 1);
        PublicCommentResource::hook('data', function (mixed $payload) {
            if (! $payload instanceof Payload || ! is_array($payload->data)) {
                throw new LogicException('The public resource hook did not receive a data payload.');
            }

            $payload->data = array_merge($payload->data, ['hook_privileged' => $payload->privileged]);

            return $payload;
        });

        $public = $this->requireObject($this->getJson('/api/meerkat/threads/api-hook/comments')->assertOk()->json('comments.0'));
        $this->assertFalse($public['hook_privileged']);

        $this->actAsModerator();
        $privileged = $this->requireObject($this->getJson('/api/meerkat/threads/api-hook/comments')->assertOk()->json('comments.0'));
        $this->assertTrue($privileged['hook_privileged']);
    }

    #[Test]
    public function include_unpublished_is_ignored_publicly_and_includes_nested_rows_for_moderators(): void
    {
        $root = CommentFactory::new()->threadId('api-unpublished')->author('Root', 'root@example.com')->text('public root')->data(['comment' => 'public root'])->published()->create();
        $child = CommentFactory::new()->replyTo($root)->author('Child', 'child@example.com')->text('private child')->data(['comment' => 'private child'])->unpublished()->create();
        $privateRoot = CommentFactory::new()->threadId('api-unpublished')->author('Private', 'private@example.com')->text('private root')->data(['comment' => 'private root'])->unpublished()->create();

        $publicIds = array_column($this->requireRows($this->getJson('/api/meerkat/threads/api-unpublished/comments?include_unpublished=1')->assertOk()->json('comments')), 'id');
        $this->assertSame([$root->id], $publicIds);

        $this->actAsModerator();
        $privilegedIds = array_column($this->requireRows($this->getJson('/api/meerkat/threads/api-unpublished/comments?include_unpublished=1')->assertOk()->json('comments')), 'id');
        $this->assertEqualsCanonicalizing([$root->id, $child->id, $privateRoot->id], $privilegedIds);
    }

    #[Test]
    public function roots_endpoint_returns_pagination_contract(): void
    {
        $this->seedThread('api-roots', 5);

        $response = $this->getJson('/api/meerkat/threads/api-roots/roots?per_page=2')->assertOk();

        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total']]);
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(5, $response->json('meta.total'));
    }

    #[Test]
    public function children_endpoint_orders_direct_replies_oldest_first(): void
    {
        $root = CommentFactory::new()->threadId('api-children-order')->published()->create();
        $newer = CommentFactory::new()->replyTo($root)->published()->create([
            'created_at' => Carbon::parse('2026-01-02 12:00:00'),
        ]);
        $older = CommentFactory::new()->replyTo($root)->published()->create([
            'created_at' => Carbon::parse('2026-01-01 12:00:00'),
        ]);

        $ids = array_column(
            $this->requireRows($this->getJson('/api/meerkat/threads/api-children-order/children/'.$root->id)
                ->assertOk()
                ->json('data')),
            'id',
        );

        $this->assertSame([$older->id, $newer->id], $ids);
    }

    #[Test]
    public function children_endpoint_prunes_replies_under_a_hidden_parent(): void
    {
        $root = CommentFactory::new()->threadId('api-children-orphan')->published()->create();
        CommentFactory::new()->replyTo($root)->published()->create();

        $root->is_published = false;
        $root->save();

        $this->getJson('/api/meerkat/threads/api-children-orphan/children/'.$root->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function stats_return_real_metrics_without_materializing_unknown_threads(): void
    {
        $this->seedThread('api-stats', 3);

        $stats = $this->getJson('/api/meerkat/threads/api-stats/stats')->assertOk();
        $this->assertSame('api-stats', $stats->json('thread_id'));
        $this->assertSame(3, $stats->json('total_comments'));

        $this->getJson('/api/meerkat/threads/does-not-exist')->assertNotFound();
        $this->getJson('/api/meerkat/threads/does-not-exist/stats')->assertNotFound();
        $this->assertFalse(ThreadMetric::query()->where('thread_id', 'does-not-exist')->exists());
    }

    #[Test]
    public function privileged_collection_endpoints_require_authentication(): void
    {
        $comment = $this->seedThread('api-gates', 1)[0];
        foreach ([
            '/api/meerkat/comments/recent?limit=10',
            '/api/meerkat/search?q=apple',
            '/api/meerkat/comments/'.$comment->id.'/history',
            '/api/meerkat/authors/a@example.com/comments',
        ] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    #[Test]
    public function recent_endpoint_returns_comment_rows_and_enforces_its_limit_cap(): void
    {
        config()->set('meerkat.api.max_per_page', 3);
        $this->actAsModerator();
        $seeded = [
            ...$this->seedThread('api-recent-a', 3),
            ...$this->seedThread('api-recent-b', 2),
        ];

        $rows = $this->requireRows($this->getJson('/api/meerkat/comments/recent?limit=999')->assertOk()->json('data'));

        $this->assertCount(3, $rows);
        $this->assertSame([], array_diff(
            $this->requireIntegerList(array_column($rows, 'id')),
            array_map(fn ($comment) => $comment->id, $seeded),
        ));
    }

    #[Test]
    public function search_validates_query_length_and_returns_only_matches(): void
    {
        $this->actAsModerator();
        CommentFactory::new()->threadId('api-search')->author('A', 'a@example.com')->text('apple pie recipe')->data(['comment' => 'apple pie recipe'])->published()->create();
        CommentFactory::new()->threadId('api-search')->author('B', 'b@example.com')->text('bread')->data(['comment' => 'bread'])->published()->create();

        $this->getJson('/api/meerkat/search?q=a')->assertUnprocessable();
        $rows = $this->requireRows($this->getJson('/api/meerkat/search?q=apple')->assertOk()->json('data'));
        $this->assertSame(['apple pie recipe'], array_column($rows, 'comment_text'));
    }

    #[Test]
    public function scoped_moderator_does_not_gain_privileged_fields_for_an_inaccessible_thread(): void
    {
        $this->createStatamicCollection('blog', 'Blog');
        $this->createStatamicCollection('docs', 'Docs');
        $this->actingAs($this->userWithPermissions('view comments', 'view blog entries'));
        CommentFactory::new()->threadId('api-docs')->collection('docs')->author('Docs', 'docs@example.com')->text('docs body')->data(['comment' => 'docs body'])->published()->create();

        $row = $this->requireObject($this->getJson('/api/meerkat/threads/api-docs/comments?include_unpublished=1')->assertOk()->json('comments.0'));
        $author = $this->requireObject($row['author']);

        $this->assertArrayNotHasKey('moderation_status', $row);
        $this->assertArrayNotHasKey('email', $author);
    }

    #[Test]
    public function privileged_endpoints_are_scoped_to_accessible_collections(): void
    {
        $this->createStatamicCollection('blog', 'Blog');
        $this->createStatamicCollection('docs', 'Docs');
        $this->actingAs($this->userWithPermissions('view comments', 'view blog entries'));
        CommentFactory::new()->threadId('api-blog-scope')->collection('blog')->author('Blog', 'blog@example.com')->text('blog apple')->data(['comment' => 'blog apple'])->published()->create();
        $docs = CommentFactory::new()->threadId('api-docs-scope')->collection('docs')->author('Docs', 'docs@example.com')->text('docs apple')->data(['comment' => 'docs apple'])->published()->create();

        $this->assertSame(['blog apple'], array_column($this->requireRows($this->getJson('/api/meerkat/search?q=apple')->assertOk()->json('data')), 'comment_text'));
        $this->assertSame([], $this->requireList($this->getJson('/api/meerkat/authors/docs@example.com/comments')->assertOk()->json('data')));
        $this->getJson('/api/meerkat/comments/'.$docs->id.'/history')->assertForbidden();
        $this->getJson('/api/meerkat/comments/'.$docs->id.'/revisions')->assertForbidden();
    }

    #[Test]
    public function author_history_enforces_the_global_limit_cap(): void
    {
        config()->set('meerkat.api.max_per_page', 2);
        $this->actAsModerator();
        for ($i = 0; $i < 4; $i++) {
            CommentFactory::new()->threadId('api-author-limit')->author('Limited', 'limited@example.com')->text("Body {$i}")->data(['comment' => "Body {$i}"])->published()->create();
        }

        $this->assertCount(2, $this->requireList($this->getJson('/api/meerkat/authors/limited@example.com/comments?limit=999')->assertOk()->json('data')));
    }

    #[Test]
    public function api_routes_disappear_when_disabled(): void
    {
        config()->set('meerkat.api.enabled', false);
        $this->seedThread('api-disabled', 1);

        $this->getJson('/api/meerkat/threads/api-disabled')->assertNotFound();
    }

    /** @return list<Comment> */
    private function seedThread(string $threadId, int $count): array
    {
        $comments = [];
        for ($i = 0; $i < $count; $i++) {
            $comments[] = CommentFactory::new()
                ->threadId($threadId)
                ->author("Author {$i}", "author{$i}@example.com")
                ->text("Body {$i}")
                ->data(['comment' => "Body {$i}"])
                ->depth(0)
                ->published()
                ->create();
        }

        return $comments;
    }

    private function actAsModerator(): User
    {
        $user = $this->userWithPermissions('view comments', 'view blog entries');
        $this->actingAs($user);

        return $user;
    }
}
