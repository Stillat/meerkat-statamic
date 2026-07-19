<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Identity;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ForgetCommandTest extends TestCase
{
    #[Test]
    public function command_validates_identity_and_mode_and_is_idempotent_for_unknown_subjects(): void
    {
        $this->pendingArtisan('meerkat:forget-identity', ['--force' => true])->expectsOutputToContain('Provide --email and/or --user-id')->assertExitCode(1);
        $this->pendingArtisan('meerkat:forget-identity', ['--email' => 'x@example.com', '--mode' => 'shred', '--force' => true])->expectsOutputToContain('Invalid --mode')->assertExitCode(1);
        $this->pendingArtisan('meerkat:forget-identity', ['--email' => 'unknown@example.com', '--force' => true])->expectsOutputToContain('Nothing to forget')->assertExitCode(0);
    }

    #[Test]
    public function anonymize_scrubs_comment_pii_and_user_meta_but_preserves_content_and_moderator_identity_by_default(): void
    {
        UserMeta::create(['user_id' => 'forget-user', 'email' => 'forget@example.com', 'name' => 'Forget']);
        $comment = CommentFactory::new()
            ->author('Forget', 'forget@example.com')
            ->text('content remains')
            ->data(['comment' => 'content remains'])
            ->create([
                'user_ip' => '203.0.113.5',
                'user_agent' => 'Agent',
                'referer' => 'https://example.com',
            ]);
        $audit = CommentModerationAudit::create(['comment_id' => $comment->id, 'actor_id' => 'forget-user', 'action' => 'published', 'details' => []]);

        $this->forget(['--email' => 'forget@example.com', '--mode' => 'anonymize']);

        $fresh = $this->requireValue($comment->fresh());
        $this->assertSame('[deleted]', $fresh->author_name);
        $this->assertNull($fresh->author_email);
        $this->assertNull($fresh->user_ip);
        $this->assertNull($fresh->user_agent);
        $this->assertNull($fresh->referer);
        $this->assertSame('content remains', $fresh->comment_text);
        $this->assertFalse($fresh->is_removed);
        $this->assertNull(UserMeta::withTrashed()->where('email', 'forget@example.com')->first());
        $this->assertSame('forget-user', $this->requireValue($audit->fresh())->actor_id);
    }

    #[Test]
    public function scrub_moderator_actions_nulls_revision_editor_and_audit_actor(): void
    {
        $comment = CommentFactory::new()->create();
        $revision = CommentRevision::create(['comment_id' => $comment->id, 'revision_number' => 2, 'comment_text' => 'edit', 'comment_data' => [], 'edited_by' => 'mod-scrub', 'edited_at' => now()]);
        $audit = CommentModerationAudit::create(['comment_id' => $comment->id, 'actor_id' => 'mod-scrub', 'action' => 'published', 'details' => []]);

        $this->forget(['--user-id' => 'mod-scrub', '--mode' => 'anonymize', '--scrub-moderator-actions' => true]);

        $this->assertNull($this->requireValue($revision->fresh())->edited_by);
        $this->assertNull($this->requireValue($audit->fresh())->actor_id);
    }

    #[Test]
    public function tombstone_mode_anonymizes_and_marks_subject_comments_removed(): void
    {
        $comment = CommentFactory::new()->author('T', 'tomb@example.com')->create();

        $this->forget(['--email' => 'tomb@example.com', '--mode' => 'tombstone']);

        $fresh = $this->requireValue($comment->fresh());
        $this->assertSame('[deleted]', $fresh->author_name);
        $this->assertNull($fresh->author_email);
        $this->assertTrue($fresh->is_removed);
        $this->assertSame('forget_request', $fresh->removed_reason);
    }

    #[Test]
    public function hard_delete_removes_subject_rows_and_cascades_descendants(): void
    {
        $subject = CommentFactory::new()->threadId('hard-forget')->author('S', 'hard@example.com')->create();
        $reply = CommentFactory::new()->threadId('hard-forget')->parent($subject->id)->depth(1)->author('Other', 'other@example.com')->create();

        $this->forget(['--email' => 'hard@example.com', '--mode' => 'hard-delete']);

        $this->assertNull(Comment::withTrashed()->find($subject->id));
        $this->assertNull(Comment::withTrashed()->find($reply->id));
    }

    #[Test]
    public function dry_run_reports_impact_without_mutation(): void
    {
        $comment = CommentFactory::new()->author('D', 'dryrun@example.com')->create();

        $this->pendingArtisan('meerkat:forget-identity', ['--email' => 'dryrun@example.com', '--dry-run' => true])
            ->expectsOutputToContain('Will affect')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame('D', $this->requireValue($comment->fresh())->author_name);
        $this->assertSame('dryrun@example.com', $this->requireValue($comment->fresh())->author_email);
    }

    /** @param array<string, mixed> $arguments */
    private function forget(array $arguments): void
    {
        $this->pendingArtisan('meerkat:forget-identity', array_merge(['--force' => true], $arguments))->assertExitCode(0);
    }
}
