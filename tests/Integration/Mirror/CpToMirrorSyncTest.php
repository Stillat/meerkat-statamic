<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Mirror\CommentParser;
use Stillat\Meerkat\Mirror\Mirror;
use Stillat\Meerkat\Tests\TestCase;

class CpToMirrorSyncTest extends TestCase
{
    private string $mirrorRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mirrorRoot = $this->temporaryDirectory('meerkat-mirror-sync-');
        config(['meerkat.mirror.enabled' => true, 'meerkat.mirror.path' => $this->mirrorRoot]);
    }

    #[Test]
    public function moderation_transitions_rewrite_published_and_spam_frontmatter(): void
    {
        $comment = $this->createComment([
            'thread_id' => 'thread-cp-moderation',
            'timestamp_id' => '1700000001',
            'is_published' => false,
            'is_spam' => false,
        ]);

        $this->assertFalse($this->frontmatter($comment)['published']);
        Comments::publish($comment->id);
        $this->assertTrue($this->frontmatter($comment)['published']);

        Comments::markAsSpam($comment->id);
        $written = $this->frontmatter($comment);
        $this->assertFalse($written['published']);
        $this->assertTrue($written['spam']);
    }

    #[Test]
    public function tombstone_restore_and_subtree_operations_rewrite_every_affected_file(): void
    {
        $root = $this->createComment([
            'thread_id' => 'thread-cp-tombstone',
            'timestamp_id' => '1700000010',
        ]);
        $child = $this->makeReply($root, '1700000011');
        $grandchild = $this->makeReply($child, '1700000012');

        Comments::deleteComment($root->id, 'spam wave');
        $this->assertTrue($this->frontmatter($root)['is_deleted']);

        Comments::restoreComment($root->id);
        $this->assertArrayNotHasKey('is_deleted', $this->frontmatter($root));

        Comments::removeSubtree($root->id, 'spam ring');
        foreach ([$root, $child, $grandchild] as $comment) {
            $this->assertTrue($this->frontmatter($comment)['is_deleted']);
        }
    }

    #[Test]
    public function authenticated_identity_is_serialized_without_emitting_a_guest_user_key(): void
    {
        $authenticated = $this->createComment([
            'thread_id' => 'thread-cp-identity',
            'timestamp_id' => '1700000020',
            'author_id' => 'user-42',
            'author_name' => 'Authed User',
            'author_email' => 'authed@example.com',
        ]);
        $guest = $this->createComment([
            'thread_id' => 'thread-cp-identity',
            'timestamp_id' => '1700000021',
            'author_id' => null,
            'author_name' => 'Guest',
            'author_email' => 'guest@example.com',
        ]);

        $this->assertSame('user-42', $this->frontmatter($authenticated)['authenticated_user']);
        $this->assertArrayNotHasKey('authenticated_user', $this->frontmatter($guest));
    }

    /** @return array<string, mixed> */
    private function frontmatter(Comment $comment): array
    {
        $path = Mirror::pathResolver()->fileFor($this->requireValue($comment->fresh()));

        return CommentParser::parse(File::get($path))['frontmatter'];
    }

    private function makeReply(Comment $parent, string $timestampId): Comment
    {
        $reply = new Comment;
        $reply->forceFill([
            'thread_id' => $parent->thread_id,
            'timestamp_id' => $timestampId,
            'parent_id' => $parent->id,
            'depth' => ($parent->depth ?? 0) + 1,
            'site' => $parent->site,
            'collection' => $parent->collection,
            'is_published' => true,
            'checked_for_spam' => false,
            'is_spam' => false,
            'is_ham' => true,
            'author_name' => 'Reply Author',
            'author_email' => 'reply@example.com',
            'comment_text' => 'a reply',
            'comment_data' => [],
        ])->save();

        return $reply;
    }
}
