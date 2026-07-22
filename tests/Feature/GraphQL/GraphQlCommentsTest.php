<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\GraphQL;

use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class GraphQlCommentsTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.graphql.enabled', true);
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('meerkat.graphql.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A custom blueprint with an extra `rating` field proves blueprint
        // fields (including site-added ones) flow through augmentation.
        app(BlueprintRepository::class)->setFallback('meerkat_gql', fn () => Blueprint::makeFromFields([
            'comment' => ['type' => 'textarea', 'display' => 'Comment'],
            'name' => ['type' => 'text', 'display' => 'Name'],
            'email' => ['type' => 'text', 'input_type' => 'email', 'display' => 'Email'],
            'website' => ['type' => 'text', 'input_type' => 'url', 'display' => 'Website'],
            'rating' => ['type' => 'integer', 'display' => 'Rating'],
        ]));

        config()->set('meerkat.form.blueprint', 'meerkat_gql');
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return TestResponse<Response>
     */
    private function graphql(string $query, array $variables = []): TestResponse
    {
        return $this->postJson('/graphql', [
            'query' => $query,
            'variables' => $variables,
        ]);
    }

    #[Test]
    public function thread_comments_query_returns_public_fields_including_custom_blueprint_field(): void
    {
        CommentFactory::new()
            ->threadId('gql-1')
            ->author('Jane')
            ->text('Hello **world**')
            ->data(['comment' => 'Hello **world**', 'rating' => 5])
            ->published()
            ->create();

        $response = $this->graphql('
            query ($t: String!) {
                meerkatComments(thread_id: $t) {
                    data { id name comment rating comment_html }
                    total
                }
            }
        ', ['t' => 'gql-1']);

        $response->assertOk();
        $response->assertJsonMissingPath('errors');

        $this->assertSame(1, $response->json('data.meerkatComments.total'));
        $row = $this->requireObject($response->json('data.meerkatComments.data.0'));
        $this->assertSame('Jane', $row['name']);
        $this->assertSame('Hello **world**', $row['comment']);
        $this->assertSame(5, $row['rating']);
        $this->assertIsString($row['comment_html']);
        $this->assertStringContainsString('<strong>world</strong>', $row['comment_html']);
    }

    #[Test]
    public function moderation_and_pii_fields_are_not_part_of_the_public_schema(): void
    {
        CommentFactory::new()
            ->threadId('gql-priv')
            ->author('Jane', 'jane@example.com')
            ->text('Hi')
            ->data(['comment' => 'Hi'])
            ->published()
            ->create();

        // Statamic GraphQL is a public/static-token API with no per-user auth
        // and viewer-agnostic response caching, so moderation/PII fields must
        // not exist on the type at all (rather than being null-gated).
        foreach (['email', 'is_spam', 'moderation_status', 'user_ip', 'author_id', 'site'] as $field) {
            $response = $this->graphql('
                query { meerkatComments(thread_id: "gql-priv") { data { '.$field.' } } }
            ');

            $errors = json_encode($response->json('errors'), JSON_THROW_ON_ERROR);
            $this->assertNotNull($response->json('errors'), "Field {$field} should not be queryable.");
            $this->assertStringContainsString($field, $errors);
        }
    }

    #[Test]
    public function spam_and_removed_comments_are_excluded_from_anonymous_requesters(): void
    {
        CommentFactory::new()->threadId('gql-hide')->author('Real')->text('legit')->data(['comment' => 'legit'])->published()->create();
        CommentFactory::new()->threadId('gql-hide')->author('Bot')->text('buy now')->data(['comment' => 'buy now'])->published()->spam()->create();
        CommentFactory::new()->threadId('gql-hide')->author('Gone')->text('removed')->data(['comment' => 'removed'])->published()->removed()->create();

        $rows = $this->requireRows($this->graphql('
            query { meerkatComments(thread_id: "gql-hide") { data { comment } } }
        ')->assertOk()->json('data.meerkatComments.data'));

        $bodies = array_column($rows, 'comment');
        $this->assertContains('legit', $bodies);
        $this->assertNotContains('buy now', $bodies);
        $this->assertNotContains('removed', $bodies);
    }

    #[Test]
    public function nested_replies_resolve(): void
    {
        $root = CommentFactory::new()->threadId('gql-nest')->author('Root')->text('root')->data(['comment' => 'root'])->published()->create();
        CommentFactory::new()->replyTo($root)->author('Child')->text('child')->data(['comment' => 'child'])->published()->create();

        $rows = $this->requireRows($this->graphql('
            query { meerkatComments(thread_id: "gql-nest") {
                data { comment replies { comment } }
            } }
        ')->assertOk()->json('data.meerkatComments.data'));

        $this->assertCount(1, $rows);
        $replies = $this->requireRows($rows[0]['replies']);
        $this->assertSame('root', $rows[0]['comment']);
        $this->assertSame('child', $replies[0]['comment']);
    }

    #[Test]
    public function single_comment_query_returns_a_public_comment(): void
    {
        $comment = CommentFactory::new()->threadId('gql-one')->author('Jane')->text('solo')->data(['comment' => 'solo'])->published()->create();
        $hidden = CommentFactory::new()->threadId('gql-one')->text('secret')->data(['comment' => 'secret'])->published()->spam()->create();

        $data = $this->requireObject($this->graphql('
            query ($id: ID!) { meerkatComment(id: $id) { id comment } }
        ', ['id' => (string) $comment->id])->assertOk()->json('data.meerkatComment'));

        $this->assertSame((string) $comment->id, $data['id']);
        $this->assertSame('solo', $data['comment']);
        $hiddenData = $this->graphql('
            query ($id: ID!) { meerkatComment(id: $id) { id } }
        ', ['id' => (string) $hidden->id])->assertOk()->json('data.meerkatComment');

        $this->assertNull($hiddenData);
    }

    #[Test]
    public function writing_a_comment_invalidates_the_cached_thread_query(): void
    {
        CommentFactory::new()->threadId('gql-cache')->text('first')->data(['comment' => 'first'])->published()->create();

        $query = 'query { meerkatComments(thread_id: "gql-cache") { total } }';

        $this->assertSame(1, $this->graphql($query)->json('data.meerkatComments.total'));

        // Confirm the response cache is actually engaged in this environment,
        // otherwise this test would be vacuous.
        $this->assertNotEmpty(Cache::get('gql-cache:tracked-responses', []));

        CommentFactory::new()->threadId('gql-cache')->text('second')->data(['comment' => 'second'])->published()->create();

        $this->assertSame(2, $this->graphql($query)->json('data.meerkatComments.total'));
    }

    #[Test]
    public function thread_query_returns_metadata(): void
    {
        CommentFactory::new()->threadId('gql-thread')->text('a')->data(['comment' => 'a'])->published()->create();
        CommentFactory::new()->threadId('gql-thread')->text('b')->data(['comment' => 'b'])->published()->create();

        $data = $this->requireObject($this->graphql('
            query { meerkatThread(id: "gql-thread") { id comments_count } }
        ')->assertOk()->json('data.meerkatThread'));

        $this->assertSame('gql-thread', $data['id']);
        $this->assertSame(2, $data['comments_count']);
    }
}
