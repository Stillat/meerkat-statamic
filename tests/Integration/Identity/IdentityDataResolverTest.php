<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Identity;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Services\Identity\IdentityDataResolver;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class IdentityDataResolverTest extends TestCase
{
    #[Test]
    public function empty_identity_resolves_to_an_empty_dataset(): void
    {
        $dataset = $this->resolver()->resolve(null, null);

        $this->assertTrue($dataset->isEmpty());
        $this->assertNull($dataset->email);
        $this->assertNull($dataset->userId);
    }

    #[Test]
    public function email_resolution_normalizes_case_and_cross_resolves_authenticated_content(): void
    {
        UserMeta::create(['user_id' => 'auth-email', 'email' => 'cross@example.com', 'name' => 'Cross']);
        $guest = CommentFactory::new()->author('A', 'cross@example.com')->create();
        $authenticated = CommentFactory::new()->authorId('auth-email')->create();

        $dataset = $this->resolver()->resolve('Cross@EXAMPLE.com', null);

        $this->assertEqualsCanonicalizing([$guest->id, $authenticated->id], $dataset->commentIds);
        $this->assertSame('cross@example.com', $dataset->email);
        $this->assertSame('auth-email', $dataset->userId);
    }

    #[Test]
    public function user_id_resolution_cross_resolves_guest_content_through_meta_email(): void
    {
        UserMeta::create(['user_id' => 'auth-id', 'email' => 'reverse@example.com', 'name' => 'Reverse']);
        $authenticated = CommentFactory::new()->authorId('auth-id')->create();
        $guest = CommentFactory::new()->author('R', 'reverse@example.com')->create();

        $dataset = $this->resolver()->resolve(null, 'auth-id');

        $this->assertEqualsCanonicalizing([$authenticated->id, $guest->id], $dataset->commentIds);
        $this->assertSame('reverse@example.com', $dataset->email);
    }

    #[Test]
    public function actor_resolution_collects_only_its_revisions_and_moderation_audits(): void
    {
        $comment = CommentFactory::new()->create();
        $mineRevision = CommentRevision::create([
            'comment_id' => $comment->id,
            'revision_number' => 2,
            'comment_text' => 'mine',
            'comment_data' => [],
            'edited_by' => 'actor-1',
            'edited_at' => now(),
        ]);
        $otherRevision = CommentRevision::create([
            'comment_id' => $comment->id,
            'revision_number' => 3,
            'comment_text' => 'other',
            'comment_data' => [],
            'edited_by' => 'actor-2',
            'edited_at' => now(),
        ]);
        $mineAudit = CommentModerationAudit::create(['comment_id' => $comment->id, 'actor_id' => 'actor-1', 'action' => 'published', 'details' => []]);
        $otherAudit = CommentModerationAudit::create(['comment_id' => $comment->id, 'actor_id' => 'actor-2', 'action' => 'rejected', 'details' => []]);

        $dataset = $this->resolver()->resolve(null, 'actor-1');

        $this->assertContains($mineRevision->id, $dataset->revisionIds);
        $this->assertNotContains($otherRevision->id, $dataset->revisionIds);
        $this->assertContains($mineAudit->id, $dataset->moderationAuditIds);
        $this->assertNotContains($otherAudit->id, $dataset->moderationAuditIds);
    }

    #[Test]
    public function collection_scope_restricts_comment_ids(): void
    {
        $blog = CommentFactory::new()->author('A', 'scope@example.com')->collection('blog')->create();
        $news = CommentFactory::new()->author('A', 'scope@example.com')->collection('news')->create();

        $dataset = $this->resolver()->resolve('scope@example.com', null, ['collection' => 'blog']);

        $this->assertContains($blog->id, $dataset->commentIds);
        $this->assertNotContains($news->id, $dataset->commentIds);
    }

    #[Test]
    public function tombstoned_content_remains_resolvable_for_compliance_operations(): void
    {
        $live = CommentFactory::new()->author('A', 'tomb@example.com')->create();
        $tombstone = CommentFactory::new()->author('A', 'tomb@example.com')->create();
        Comments::deleteComment($tombstone->id);

        $dataset = $this->resolver()->resolve('tomb@example.com', null);

        $this->assertContains($live->id, $dataset->commentIds);
        $this->assertContains($tombstone->id, $dataset->commentIds);
    }

    #[Test]
    public function subject_hash_is_deterministic_and_identity_specific(): void
    {
        $first = $this->resolver()->resolve('one@example.com', null)->subjectHash();
        $same = $this->resolver()->resolve('one@example.com', null)->subjectHash();
        $different = $this->resolver()->resolve('two@example.com', null)->subjectHash();

        $this->assertSame($first, $same);
        $this->assertNotSame($first, $different);
    }

    private function resolver(): IdentityDataResolver
    {
        return app(IdentityDataResolver::class);
    }
}
