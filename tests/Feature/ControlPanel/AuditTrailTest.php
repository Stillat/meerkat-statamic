<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\ControlPanel;

use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Http\Controllers\CP\CommentController as CpCommentController;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class AuditTrailTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('meerkat.revisions.enabled', true);
    }

    #[Test]
    public function moderation_status_transitions_write_the_expected_actor_action_and_diff(): void
    {
        $this->actAsAdmin();
        foreach ([
            ['pending', false, 'approved', 'published'],
            ['approved', true, 'spam', 'marked_spam'],
            ['approved', true, 'rejected', 'rejected'],
            ['approved', true, 'pending', 'unpublished'],
        ] as [$from, $published, $to, $action]) {
            $comment = CommentFactory::new()->threadId('audit-status')->published($published)->create(['moderation_status' => $from]);
            $this->update($comment, ['moderation_status' => $to, 'moderation_reason' => $to === 'rejected' ? 'policy' : null])->assertSuccessful();
            $audit = CommentModerationAudit::query()->where('comment_id', $comment->id)->latest()->firstOrFail();

            $this->assertSame($action, $audit->action);
            $this->assertSame('cp-audit-admin', $audit->actor_id);
            $details = $this->requireValue($audit->details);
            $status = $details['moderation_status'] ?? null;
            $this->assertIsArray($status);
            $this->assertSame($from, $status['from']);
            $this->assertSame($to, $status['to']);
        }
    }

    #[Test]
    public function notes_only_edit_writes_an_updated_audit_without_a_status_diff(): void
    {
        $this->actAsAdmin();
        $comment = CommentFactory::new()->threadId('audit-notes')->published()->create(['moderation_status' => 'approved', 'moderation_notes' => null]);

        $this->update($comment, ['moderation_notes' => 'Reviewed by moderator'])->assertSuccessful();
        $audit = CommentModerationAudit::query()->where('comment_id', $comment->id)->latest()->firstOrFail();

        $this->assertSame('updated', $audit->action);
        $details = $this->requireValue($audit->details);
        $notes = $details['moderation_notes'] ?? null;
        $this->assertIsArray($notes);
        $this->assertSame('Reviewed by moderator', $notes['to']);
        $this->assertArrayNotHasKey('moderation_status', $details);
    }

    #[Test]
    public function content_only_edit_creates_a_revision_without_polluting_the_audit_log(): void
    {
        $this->actAsAdmin();
        $comment = CommentFactory::new()->threadId('audit-content')->text('Original')->data(['comment' => 'Original'])->published()->create(['moderation_status' => 'approved']);
        $audits = CommentModerationAudit::query()->where('comment_id', $comment->id)->count();
        $revisions = CommentRevision::query()->where('comment_id', $comment->id)->count();

        $this->update($comment, ['comment' => 'Updated'])->assertSuccessful();

        $this->assertSame($audits, CommentModerationAudit::query()->where('comment_id', $comment->id)->count());
        $this->assertSame($revisions + 1, CommentRevision::query()->where('comment_id', $comment->id)->count());
        $this->assertSame('Updated', $this->requireValue($comment->fresh())->comment_text);
    }

    #[Test]
    public function controller_hook_runs_before_model_hooks_and_its_mutation_is_audited(): void
    {
        $this->actAsAdmin();
        $this->resetStatamicHooks();
        $comment = CommentFactory::new()->threadId('audit-hook')->published()->create(['moderation_status' => 'approved']);
        $calls = [];
        CpCommentController::hook('before-updating-comment', function (mixed $payload) use (&$calls) {
            if (! $payload instanceof Payload || ! $payload->comment instanceof Comment) {
                throw new LogicException('The controller hook did not receive a comment payload.');
            }

            $calls[] = 'controller';
            $payload->comment->moderation_notes = 'Changed by hook';

            return $payload;
        });
        Comment::hook('saving', function (mixed $payload) use (&$calls, $comment) {
            if (! $payload instanceof Payload || ! $payload->model instanceof Comment) {
                throw new LogicException('The saving hook did not receive a comment payload.');
            }

            if ($payload->model->id === $comment->id) {
                $calls[] = 'saving';
            }

            return $payload;
        });
        Comment::hook('saved', function (mixed $payload) use (&$calls, $comment) {
            if (! $payload instanceof Payload || ! $payload->model instanceof Comment) {
                throw new LogicException('The saved hook did not receive a comment payload.');
            }

            if ($payload->model->id === $comment->id) {
                $calls[] = 'saved';
            }

            return $payload;
        });

        $this->update($comment, ['comment' => 'Updated'])->assertSuccessful();

        $this->assertSame(['controller', 'saving', 'saved'], $calls);
        $this->assertSame('Changed by hook', $this->requireValue($comment->fresh())->moderation_notes);
        $audit = CommentModerationAudit::query()->where('comment_id', $comment->id)->latest()->firstOrFail();
        $details = $this->requireValue($audit->details);
        $notes = $details['moderation_notes'] ?? null;
        $this->assertIsArray($notes);
        $this->assertSame('Changed by hook', $notes['to']);
    }

    #[Test]
    public function no_op_save_writes_nothing_while_combined_content_and_status_changes_write_both_records(): void
    {
        $this->actAsAdmin();
        $noOp = CommentFactory::new()->threadId('audit-noop')->published()->create(['moderation_status' => 'approved']);
        $before = CommentModerationAudit::query()->where('comment_id', $noOp->id)->count();
        $this->update($noOp)->assertSuccessful();
        $this->assertSame($before, CommentModerationAudit::query()->where('comment_id', $noOp->id)->count());

        $combined = CommentFactory::new()->threadId('audit-combined')->text('Original')->data(['comment' => 'Original'])->published(false)->create(['moderation_status' => 'pending']);
        $this->update($combined, ['comment' => 'Reworded', 'moderation_status' => 'approved'])->assertSuccessful();

        $this->assertSame('published', CommentModerationAudit::query()->where('comment_id', $combined->id)->latest()->value('action'));
        $this->assertSame('Reworded', CommentRevision::query()->where('comment_id', $combined->id)->latest('revision_number')->value('comment_text'));
    }

    private function actAsAdmin(): void
    {
        $collection = $this->makeStatamicCollection('blog');
        $collection->title('Blog');
        $collection->save();
        $this->makeAdmin('cp-audit-admin');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function update(Comment $comment, array $overrides = []): TestResponse
    {
        return $this->putJson(cp_route('meerkat.comment.update', ['id' => $comment->id]), array_merge([
            'comment' => $comment->comment_text,
            'name' => $comment->author_name,
            'email' => $comment->author_email,
            'website' => null,
            'is_published' => (bool) $comment->is_published,
            'is_spam' => (bool) $comment->is_spam,
            'moderation_status' => $comment->moderation_status ?? 'approved',
            'moderation_reason' => $comment->moderation_reason,
            'moderation_notes' => $comment->moderation_notes,
            'thread_id' => [$comment->thread_id],
            'collection' => [$comment->collection],
            'site' => [$comment->site],
            'author_id' => [],
            'created_at' => $this->statamicDate($comment->created_at ?? now()),
        ], $overrides));
    }
}
