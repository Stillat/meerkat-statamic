<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Listeners\CapturesCommentRevisions;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class ThreadAndRevisionMirrorTest extends TestCase
{
    private string $mirrorRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mirrorRoot = $this->temporaryDirectory('meerkat-mirror-aux-');
        config([
            'meerkat.mirror.enabled' => true,
            'meerkat.mirror.path' => $this->mirrorRoot,
            'meerkat.revisions.enabled' => true,
        ]);
        CapturesCommentRevisions::register();
    }

    #[Test]
    public function thread_meta_sidecar_tracks_creation_soft_delete_and_restore(): void
    {
        $thread = $this->createThread('thread-meta');
        $meta = $this->metaFor('thread-meta');
        $this->assertFalse($meta['trashed']);
        $this->assertSame($this->requireValue($thread->created_at)->getTimestamp(), $meta['created']);
        $this->assertSame([], $meta['attributes']);

        $thread->delete();
        $this->assertTrue($this->metaFor('thread-meta')['trashed']);

        $thread->restore();
        $this->assertFalse($this->metaFor('thread-meta')['trashed']);
    }

    #[Test]
    public function force_deleting_a_thread_removes_its_complete_mirror_directory(): void
    {
        $this->createThread('thread-gone');
        $this->createComment(['thread_id' => 'thread-gone', 'timestamp_id' => '1700001000']);
        $threadDir = $this->mirrorRoot.'/thread-gone';
        $this->assertFileExists($threadDir.'/.meta');

        Thread::query()->where('thread_id', 'thread-gone')->firstOrFail()->forceDelete();

        $this->assertDirectoryDoesNotExist($threadDir);
    }

    #[Test]
    public function revision_sidecar_tracks_content_edits_but_not_moderation_only_saves(): void
    {
        $this->createThread('thread-revisions');
        $comment = $this->createComment([
            'thread_id' => 'thread-revisions',
            'timestamp_id' => '1700003000',
            'comment_text' => 'first draft',
            'is_published' => false,
        ]);

        $initial = $this->revisionsFor($comment);
        $this->assertSame(1, $initial['revision']);
        $this->assertCount(1, $initial['changes']);
        $this->assertNotEmpty($initial['changes'][0]['edited_at']);

        $comment->comment_text = 'second draft';
        $comment->save();
        $comment->comment_text = 'third draft';
        $comment->save();
        $edited = $this->revisionsFor($comment);
        $this->assertSame(3, $edited['revision']);
        $this->assertSame([1, 2, 3], array_column($edited['changes'], 'revision'));

        $comment->is_published = true;
        $comment->moderation_status = 'approved';
        $comment->save();
        $moderated = $this->revisionsFor($comment);
        $this->assertSame(3, $moderated['revision']);
        $this->assertCount(3, $moderated['changes']);
    }

    #[Test]
    public function skip_hooks_path_materialization_does_not_create_a_fake_revision(): void
    {
        $this->createThread('thread-skip-hooks');
        $comment = new Comment;
        $comment->forceFill([
            'thread_id' => 'thread-skip-hooks',
            'timestamp_id' => '1700005000',
            'site' => 'default',
            'collection' => 'blog',
            'is_published' => true,
            'checked_for_spam' => false,
            'is_spam' => false,
            'is_ham' => true,
            'author_name' => 'A',
            'author_email' => 'a@example.com',
            'depth' => 0,
            'comment_text' => 'hello',
            'comment_data' => [],
        ])->save();

        $comment->skipHooks = true;
        $comment->path = (string) $comment->id;
        $comment->visual_path = '000001';
        $comment->save();

        $this->assertSame(1, CommentRevision::query()->where('comment_id', $comment->id)->count());
    }

    /** @return array<string, mixed> */
    private function metaFor(string $threadId): array
    {
        $path = $this->mirrorRoot.'/'.$threadId.'/.meta';
        $this->assertFileExists($path);

        return $this->requireObject(Yaml::parse(File::get($path)) ?? []);
    }

    /** @return array{revision: mixed, changes: list<array<string, mixed>>} */
    private function revisionsFor(Comment $comment): array
    {
        $path = $this->mirrorRoot.'/'.$comment->thread_id.'/'.$comment->timestamp_id.'/.revisions';
        $this->assertFileExists($path);

        $data = $this->requireObject(Yaml::parse(File::get($path)) ?? []);

        return [
            'revision' => $data['revision'] ?? null,
            'changes' => $this->requireRows($data['changes'] ?? []),
        ];
    }
}
