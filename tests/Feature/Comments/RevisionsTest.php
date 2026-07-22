<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Comments;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class RevisionsTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('meerkat.revisions.enabled', true);
    }

    #[Test]
    public function content_edits_create_ordered_revisions_with_editor_identity_but_moderation_changes_do_not(): void
    {
        $this->actAsAdmin();
        $comment = CommentFactory::new()->threadId('revision-lifecycle')->author('A', 'a@example.com')->text('v1')->data(['comment' => 'v1'])->published()->create();

        $comment->comment_text = 'v2';
        $comment->save();
        $comment->comment_text = 'v3';
        $comment->save();
        $comment->moderation_status = 'rejected';
        $comment->moderation_reason = 'policy';
        $comment->is_published = false;
        $comment->save();

        $revisions = CommentRevision::query()->where('comment_id', $comment->id)->orderBy('revision_number')->get();
        $this->assertSame([1, 2, 3], $revisions->pluck('revision_number')->all());
        $this->assertSame(['v1', 'v2', 'v3'], $revisions->pluck('comment_text')->all());
        $this->assertSame(['rev-admin'], $revisions->pluck('edited_by')->unique()->values()->all());
    }

    #[Test]
    public function revision_endpoint_and_model_helpers_return_the_expected_history(): void
    {
        $this->actAsAdmin();
        $comment = CommentFactory::new()->threadId('revision-read')->author('A', 'a@example.com')->text('v1')->data(['comment' => 'v1'])->published()->create();
        $comment->comment_text = 'v2';
        $comment->save();
        $comment->comment_text = 'v3';
        $comment->save();

        $rows = $this->requireRows($this->getJson('/api/meerkat/comments/'.$comment->id.'/revisions')->assertOk()->json('revisions'));

        $this->assertSame([3, 2, 1], array_column($rows, 'revision_number'));
        $this->assertSame(['v3', 'v2', 'v1'], array_column($rows, 'comment_text'));
        $this->assertSame(3, $this->requireValue($comment->latestRevision())->revision_number);
        $this->assertSame('v1', $this->requireValue($comment->revision(1))->comment_text);
        $this->assertNull($comment->revision(99));
    }

    #[Test]
    public function restoring_a_revision_reverts_content_and_records_the_restore(): void
    {
        $this->actAsAdmin();
        $comment = CommentFactory::new()->threadId('revision-restore')->author('A', 'a@example.com')->text('v1')->data(['comment' => 'v1'])->published()->create();
        $comment->comment_text = 'v2';
        $comment->save();
        $comment->comment_text = 'v3';
        $comment->save();

        $this->assertTrue(app(CommentRepository::class)->restoreRevision($comment->id, 1));

        $fresh = $this->requireValue($comment->fresh());
        $this->assertSame('v1', $fresh->comment_text);
        $latestRevision = $this->requireValue($fresh->latestRevision());
        $this->assertSame(4, $latestRevision->revision_number);
        $this->assertSame('Restored from revision 1', $latestRevision->edit_reason);
    }

    #[Test]
    public function restore_endpoint_requires_edit_permission_and_persists_the_selected_revision(): void
    {
        $this->createStatamicCollection('blog', 'Blog');
        $comment = CommentFactory::new()->threadId('revision-permission')->author('A', 'a@example.com')->text('v1')->data(['comment' => 'v1'])->published()->create();
        $comment->comment_text = 'v2';
        $comment->save();
        $url = cp_route('meerkat.comment.revision.restore', ['id' => $comment->id, 'revisionNumber' => 1]);

        $this->actingAs($this->userWithPermissions('view comments'));
        $this->postJson($url)->assertForbidden();

        $this->makeAdmin('revision-editor', 'revision-editor@example.com');
        $this->postJson($url)->assertOk()->assertJson(['restored' => true]);
        $this->assertSame('v1', $this->requireValue($comment->fresh())->comment_text);
    }

    private function actAsAdmin(): User
    {
        $this->createStatamicCollection('blog', 'Blog');

        return $this->makeAdmin('rev-admin', 'rev@example.com');
    }
}
