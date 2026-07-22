<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\ControlPanel;

use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\User;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Http\Controllers\CP\CommentController as CpCommentController;
use Stillat\Meerkat\Http\Resources\Comments\CommentResource;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class CommentsHttpTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('meerkat.revisions.enabled', true);
    }

    #[Test]
    public function index_caps_oversized_pages(): void
    {
        config()->set('meerkat.cp.max_per_page', 2);
        $this->actAsAdmin();

        $this->comment('cp-index-cap', 'One', 'one');
        $this->comment('cp-index-cap', 'Two', 'two');
        $this->comment('cp-index-cap', 'Three', 'three');

        $page = $this->getJson(cp_route('meerkat.cp.comments.index').'?perPage=999')
            ->assertOk();

        $page->assertJsonStructure(['data', 'meta']);
        $this->assertCount(2, $this->requireList($page->json('data')));
    }

    #[Test]
    public function index_filters_safely_and_returns_hookable_enriched_rows(): void
    {
        $this->resetStatamicHooks();
        $this->actAsAdmin();

        CommentResource::hook('values', function (mixed $payload) {
            if (! $payload instanceof Payload || ! is_array($payload->values)) {
                throw new LogicException('The values hook did not receive a values payload.');
            }

            $payload->values = array_merge($payload->values, ['hooked_value' => 'from hook']);

            return $payload;
        });

        $parent = $this->comment('cp-index', 'Root', 'parent body');
        CommentFactory::new()
            ->threadId('cp-index')
            ->parent($parent->id)
            ->depth(1)
            ->author('Replier', 'replier@example.com')
            ->text('**needle** <script>alert(1)</script>')
            ->data(['comment' => '**needle** <script>alert(1)</script>'])
            ->published()
            ->create();
        $rows = $this->requireRows($this->getJson(
            cp_route('meerkat.cp.comments.index').'?search=needle&sort=comment_data->body&order=desc;drop table comments',
        )->assertOk()->json('data'));

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $author = $this->requireObject($row['author']);
        $parentSummary = $this->requireObject($row['parent_summary']);
        $this->assertSame('Replier', $author['name']);
        $this->assertSame('R', $author['initials']);
        $this->assertTrue($author['is_guest']);
        $this->assertIsString($row['comment_html']);
        $this->assertStringContainsString('<strong>needle</strong>', $row['comment_html']);
        $this->assertStringNotContainsString('<script', $row['comment_html']);
        $this->assertSame($parent->id, $parentSummary['id']);
        $this->assertSame('Root', $parentSummary['author_name']);
        $this->assertSame('from hook', $row['hooked_value']);
        $this->assertArrayHasKey('actions', $row);
    }

    #[Test]
    public function index_sorts_by_resolved_authors_and_custom_blueprint_fields(): void
    {
        $this->actAsAdmin();

        $zed = CommentFactory::new()
            ->threadId('cp-sort-zed')
            ->author('Zed', 'zed@example.com')
            ->text('Zed comment')
            ->data(['comment' => 'Zed comment', 'website' => 'https://alpha.example.com'])
            ->published()
            ->create();
        $amy = CommentFactory::new()
            ->threadId('cp-sort-amy')
            ->author('Amy', 'amy@example.com')
            ->text('Amy comment')
            ->data(['comment' => 'Amy comment', 'website' => 'https://zulu.example.com'])
            ->published()
            ->create();

        $byAuthor = $this->requireRows($this->getJson(
            cp_route('meerkat.cp.comments.index').'?sort=name&order=asc',
        )->assertOk()->json('data'));
        $this->assertSame([$amy->id, $zed->id], array_column($byAuthor, 'id'));

        $byWebsite = $this->requireRows($this->getJson(
            cp_route('meerkat.cp.comments.index').'?sort=website&order=asc',
        )->assertOk()->json('data'));
        $this->assertSame([$zed->id, $amy->id], array_column($byWebsite, 'id'));
    }

    #[Test]
    public function thread_endpoint_returns_the_complete_moderation_tree(): void
    {
        $this->actAsAdmin();
        $root = $this->comment('cp-thread-view', 'Root', 'root body');
        CommentFactory::new()
            ->threadId('cp-thread-view')
            ->parent($root->id)
            ->depth(1)
            ->author('Replier', 'replier@example.com')
            ->text('reply body')
            ->data(['comment' => 'reply body'])
            ->pending()
            ->create();

        $response = $this->getJson(cp_route('meerkat.comments.thread', ['threadId' => 'cp-thread-view']))
            ->assertOk();
        $comments = $this->requireRows($response->json('comments'));

        $this->assertNotNull($response->json('thread.title'));
        $this->assertCount(2, $comments);
        $this->assertSame([0, 1], array_column($comments, 'depth'));
        $this->assertSame($root->id, $comments[1]['parent_id']);
        $this->assertSame('pending', $comments[1]['moderation_status']);
        $this->assertSame('Root', $comments[1]['parent_author']);
        $this->assertIsString($comments[0]['comment_html']);
        $this->assertStringContainsString('root body', $comments[0]['comment_html']);
        $this->assertIsArray($comments[0]['actions']);
    }

    #[Test]
    public function exports_return_csv_and_json_downloads_with_comment_data(): void
    {
        $this->actAsAdmin();
        $this->comment('cp-export', 'Exporter', 'export marker');

        $csv = $this->get(cp_route('meerkat.comments.export'));
        $csv->assertOk()->assertDownload();
        $this->assertStringStartsWith('text/csv', (string) $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('export marker', $csv->streamedContent() ?: (string) $csv->getContent());

        $json = $this->get(cp_route('meerkat.comments.export').'?format=json');
        $json->assertOk()->assertDownload();
        $this->assertStringStartsWith('application/json', (string) $json->headers->get('Content-Type'));
        $this->assertStringContainsString('.json', (string) $json->headers->get('content-disposition'));
    }

    #[Test]
    public function exports_follow_the_requested_sort_order(): void
    {
        $this->actAsAdmin();

        $older = CommentFactory::new()->threadId('cp-export-sort')->text('older')->published()->create([
            'created_at' => Carbon::parse('2026-01-01 12:00:00'),
        ]);
        $newer = CommentFactory::new()->threadId('cp-export-sort')->text('newer')->published()->create([
            'created_at' => Carbon::parse('2026-01-02 12:00:00'),
        ]);

        $response = $this->get(cp_route('meerkat.comments.export').'?format=json&sort=created_at&order=desc')
            ->assertOk()
            ->assertDownload();
        $contents = $response->streamedContent() ?: (string) $response->getContent();
        $payload = $this->requireObject(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
        $comments = $this->requireRows($payload['comments']);

        $this->assertSame([$newer->id, $older->id], array_column($comments, 'id'));
    }

    #[Test]
    public function comment_values_and_history_endpoints_return_their_public_payloads(): void
    {
        $this->actAsAdmin();
        $comment = $this->comment('cp-values-history', 'Author', 'Some body');
        CommentModerationAudit::create([
            'comment_id' => $comment->id,
            'thread_id' => $comment->thread_id,
            'actor_id' => 'cp-admin',
            'action' => 'marked_ham',
            'details' => [],
        ]);

        $values = $this->getJson(cp_route('meerkat.comment.get', ['id' => $comment->id]))
            ->assertOk();
        $values->assertJsonStructure(['meta', 'values']);
        $this->assertSame('Some body', $values->json('values.comment'));

        $history = $this->getJson(cp_route('meerkat.comment.history', ['id' => $comment->id]))
            ->assertOk();
        $this->assertCount(1, $this->requireList($history->json('history')));
        $this->assertSame('marked_ham', $history->json('history.0.action'));
    }

    #[Test]
    public function reply_blueprint_and_submission_return_the_saved_nested_comment(): void
    {
        $this->actAsAdmin();
        $parent = $this->comment('cp-reply', 'Parent', 'Parent body');

        $this->getJson(cp_route('meerkat.comment.reply-data', ['parent' => $parent->id]))
            ->assertOk()
            ->assertJsonStructure(['meta', 'values']);

        $response = $this->postJson(cp_route('meerkat.comment.reply', ['parent' => $parent->id]), [
            'comment' => 'inline reply body',
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ])->assertSuccessful();

        $this->assertSame('inline reply body', $response->json('data.comment_text'));
        $commentHtml = $response->json('data.comment_html');
        $this->assertIsString($commentHtml);
        $this->assertStringContainsString('inline reply body', $commentHtml);
        $this->assertSame($parent->id, $response->json('data.parent_summary.id'));
        $this->assertDatabaseHas('comments', [
            'parent_id' => $parent->id,
            'depth' => 1,
            'comment_text' => 'inline reply body',
        ], 'meerkat');
    }

    #[Test]
    public function before_saving_reply_hook_can_mutate_the_persisted_reply(): void
    {
        $this->resetStatamicHooks();
        $this->actAsAdmin();
        $parent = $this->comment('cp-reply-hook', 'Parent', 'Parent body');

        CpCommentController::hook('before-saving-reply', function (mixed $payload) {
            if (! $payload instanceof Payload || ! $payload->comment instanceof Comment) {
                throw new LogicException('The reply hook did not receive a comment payload.');
            }

            $payload->comment->comment_text = 'Reply changed by hook';
            $commentData = $payload->comment->comment_data;
            $payload->comment->comment_data = array_merge(
                $commentData,
                ['hooked_reply' => true],
            );

            return $payload;
        });

        $response = $this->postJson(cp_route('meerkat.comment.reply', ['parent' => $parent->id]), [
            'comment' => 'Original reply',
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ])->assertSuccessful();

        $this->assertSame('Reply changed by hook', $response->json('data.comment_text'));
        $reply = Comment::query()->where('parent_id', $parent->id)->firstOrFail();
        $this->assertTrue($reply->comment_data['hooked_reply']);
    }

    #[Test]
    public function revisions_identify_registered_editors_and_fall_back_to_guest_authors(): void
    {
        $this->actAsAdmin();
        $editor = User::make();

        if (! $editor instanceof \Statamic\Auth\File\User && ! $editor instanceof \Statamic\Auth\Eloquent\User) {
            throw new LogicException('Statamic did not create a user.');
        }

        $editor->id('jane-editor');
        $editor->email('jane@example.com');
        $editor->data(['name' => 'Jane Editor']);
        $editor->save();

        $edited = $this->comment('cp-editor-revisions', 'Original Author', 'Body');
        CommentRevision::query()->create([
            'comment_id' => $edited->id,
            'revision_number' => 2,
            'comment_text' => 'edit',
            'changes' => ['comment_text' => ['from' => 'Body', 'to' => 'edit']],
            'edited_at' => now(),
            'edited_by' => 'jane-editor',
        ]);

        $editorRows = $this->requireRows($this->getJson(cp_route('meerkat.comment.revisions', ['id' => $edited->id]))
            ->assertOk()
            ->json('revisions'));
        $this->assertGreaterThanOrEqual(2, count($editorRows));
        $editorUser = $this->requireObject($editorRows[0]['user']);
        $this->assertTrue($editorRows[0]['current']);
        $this->assertSame('jane-editor', $editorRows[0]['edited_by']);
        $this->assertSame('jane-editor', $editorUser['id']);
        $this->assertSame('jane@example.com', $editorUser['email']);
        $this->assertSame('Jane Editor', $editorUser['name']);
        $this->assertArrayHasKey('initials', $editorUser);

        $guest = $this->comment('cp-guest-revisions', 'Guest Patty', 'Guest body');
        CommentRevision::query()->where('comment_id', $guest->id)->update(['edited_by' => null]);
        $guestRow = $this->requireObject($this->getJson(cp_route('meerkat.comment.revisions', ['id' => $guest->id]))
            ->assertOk()
            ->json('revisions.0'));
        $guestUser = $this->requireObject($guestRow['user']);

        $this->assertNull($guestRow['edited_by']);
        $this->assertNull($guestUser['id']);
        $this->assertSame('Guest Patty', $guestUser['name']);
        $this->assertSame('guest-patty@example.com', $guestUser['email']);
        $this->assertSame('GP', $guestUser['initials']);
    }

    private function actAsAdmin(): \Statamic\Contracts\Auth\User
    {
        $collection = Collection::make('blog');
        $collection->title('Blog');
        $collection->save();

        return $this->makeAdmin('cp-admin', 'admin@example.com');
    }

    private function comment(string $thread, string $author, string $text): Comment
    {
        return CommentFactory::new()
            ->threadId($thread)
            ->collection('blog')
            ->author($author, strtolower(str_replace(' ', '-', $author)).'@example.com')
            ->text($text)
            ->data(['comment' => $text])
            ->published()
            ->create();
    }
}
