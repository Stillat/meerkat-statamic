<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Mirror\CommentParser;
use Stillat\Meerkat\Mirror\MirrorPathResolver;
use Stillat\Meerkat\Mirror\MirrorWriter;
use Stillat\Meerkat\Tests\TestCase;

class MirrorWriterTest extends TestCase
{
    private string $mirrorRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mirrorRoot = $this->temporaryDirectory('meerkat-mirror-test-');

        config(['meerkat.mirror.enabled' => true, 'meerkat.mirror.path' => $this->mirrorRoot]);
    }

    #[Test]
    public function it_writes_a_root_comment_to_thread_timestamp_layout(): void
    {
        $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
            'author_name' => 'Maya',
            'author_email' => 'maya@example.com',
            'comment_text' => 'Treehouse forever.',
        ]);

        $expected = $this->mirrorRoot.'/thread-abc/1779039555/comment.md';

        $this->assertFileExists($expected);
        $parsed = CommentParser::parse(File::get($expected));
        $this->assertSame('1779039555', $parsed['frontmatter']['id']);
        $this->assertSame('Treehouse forever.', $parsed['body']);
    }

    #[Test]
    public function it_writes_a_nested_reply_under_the_parents_replies_directory(): void
    {
        $root = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
            'comment_text' => 'root',
        ]);

        $reply = new Comment;
        $reply->thread_id = 'thread-abc';
        $reply->timestamp_id = '1779039592';
        $reply->parent_id = $root->id;
        $reply->depth = 1;
        $reply->site = 'default';
        $reply->collection = 'blog';
        $reply->is_published = true;
        $reply->checked_for_spam = false;
        $reply->is_spam = false;
        $reply->is_ham = true;
        $reply->author_name = 'Devon';
        $reply->author_email = 'devon@example.com';
        $reply->comment_text = 'a reply';
        $reply->comment_data = [];
        $reply->save();

        $expected = $this->mirrorRoot.'/thread-abc/1779039555/replies/1779039592/comment.md';
        $this->assertFileExists($expected);
    }

    #[Test]
    public function it_overwrites_existing_files_on_update(): void
    {
        $comment = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
            'comment_text' => 'first version',
        ]);

        $comment->comment_text = 'second version';
        $comment->save();

        $file = $this->mirrorRoot.'/thread-abc/1779039555/comment.md';
        $parsed = CommentParser::parse(File::get($file));

        $this->assertSame('second version', $parsed['body']);
    }

    #[Test]
    public function it_removes_the_file_on_force_delete_and_cleans_up_the_directory(): void
    {
        $comment = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
        ]);

        $file = $this->mirrorRoot.'/thread-abc/1779039555/comment.md';
        $this->assertFileExists($file);

        $comment->forceDelete();

        $this->assertFileDoesNotExist($file);
        $this->assertDirectoryDoesNotExist(dirname($file));
    }

    #[Test]
    public function it_is_a_noop_when_the_mirror_is_disabled(): void
    {
        config(['meerkat.mirror.enabled' => false]);

        $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
        ]);

        $this->assertDirectoryDoesNotExist($this->mirrorRoot.'/thread-abc');
    }

    #[Test]
    public function it_backfills_a_timestamp_id_when_missing_at_first_write(): void
    {
        $comment = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => null,
        ]);

        $fresh = $this->requireValue(Comment::query()->find($comment->id));

        $this->assertNotNull($fresh->timestamp_id);
        $this->assertMatchesRegularExpression('/^\d+$/', $fresh->timestamp_id);
        $this->assertFileExists($this->mirrorRoot.'/thread-abc/'.$fresh->timestamp_id.'/comment.md');
    }

    #[Test]
    public function suppress_blocks_writes_inside_the_callback(): void
    {
        $callbackRan = false;

        MirrorWriter::suppress(function () use (&$callbackRan) {
            $this->createComment([
                'thread_id' => 'thread-abc',
                'timestamp_id' => '1779039555',
            ]);
            $callbackRan = true;
        });

        $this->assertTrue($callbackRan);
        $this->assertDirectoryDoesNotExist($this->mirrorRoot.'/thread-abc/1779039555');
    }

    #[Test]
    public function force_deleting_a_parent_with_replies_removes_the_entire_directory(): void
    {
        $root = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
            'comment_text' => 'root',
        ]);

        $reply = new Comment;
        $reply->thread_id = 'thread-abc';
        $reply->timestamp_id = '1779039592';
        $reply->parent_id = $root->id;
        $reply->depth = 1;
        $reply->site = 'default';
        $reply->collection = 'blog';
        $reply->is_published = true;
        $reply->checked_for_spam = false;
        $reply->is_spam = false;
        $reply->is_ham = true;
        $reply->author_name = 'Devon';
        $reply->author_email = 'devon@example.com';
        $reply->comment_text = 'a reply';
        $reply->comment_data = [];
        $reply->save();

        $root->forceDelete();

        $this->assertDirectoryDoesNotExist(
            $this->mirrorRoot.'/thread-abc/1779039555',
            'A leftover empty replies/ subdirectory would orphan the comment directory and fail every later sync.'
        );
    }

    #[Test]
    public function it_backfills_ancestors_created_while_the_mirror_was_disabled(): void
    {
        config(['meerkat.mirror.enabled' => false]);

        $root = $this->createComment([
            'thread_id' => 'thread-late',
            'timestamp_id' => null,
            'comment_text' => 'created before the mirror existed',
        ]);

        $this->assertNull($this->requireValue(Comment::query()->find($root->id))->timestamp_id);

        config(['meerkat.mirror.enabled' => true]);

        $reply = new Comment;
        $reply->thread_id = 'thread-late';
        $reply->parent_id = $root->id;
        $reply->depth = 1;
        $reply->site = 'default';
        $reply->collection = 'blog';
        $reply->is_published = true;
        $reply->checked_for_spam = false;
        $reply->is_spam = false;
        $reply->is_ham = true;
        $reply->author_name = 'Devon';
        $reply->author_email = 'devon@example.com';
        $reply->comment_text = 'a reply after enabling the mirror';
        $reply->comment_data = [];
        $reply->save();

        $freshRoot = $this->requireValue(Comment::query()->find($root->id));
        $freshReply = $this->requireValue(Comment::query()->find($reply->id));

        $this->assertNotNull($freshRoot->timestamp_id);
        $this->assertNotNull($freshReply->timestamp_id);
        $this->assertFileExists($this->mirrorRoot.'/thread-late/'.$freshRoot->timestamp_id.'/comment.md');
        $this->assertFileExists($this->mirrorRoot.'/thread-late/'.$freshRoot->timestamp_id.'/replies/'.$freshReply->timestamp_id.'/comment.md');
    }

    #[Test]
    public function it_takes_the_next_second_when_a_concurrent_submission_claims_the_timestamp(): void
    {
        $this->createComment([
            'thread_id' => 'thread-race',
            'timestamp_id' => '1779039555',
        ]);

        $late = $this->createComment([
            'thread_id' => 'thread-race',
            'timestamp_id' => null,
        ]);
        Comment::query()->where('comments.id', $late->id)->update(['timestamp_id' => null]);
        $late->refresh();
        $late->created_at = Carbon::createFromTimestampUTC(1779039555);

        $writer = new class(new MirrorPathResolver($this->mirrorRoot)) extends MirrorWriter
        {
            protected function isTaken(string $threadId, string $candidate, int $selfId): bool
            {
                return false;
            }
        };

        $writer->write($late);

        $this->assertSame('1779039556', $this->requireValue(Comment::query()->find($late->id))->timestamp_id);
    }

    #[Test]
    public function path_resolver_throws_when_ancestor_is_missing_a_timestamp_id(): void
    {
        $root = $this->createComment([
            'thread_id' => 'thread-x',
            'timestamp_id' => null,
        ]);

        Comment::query()->where('comments.id', $root->id)->update(['timestamp_id' => null]);
        $root->refresh();

        $resolver = new MirrorPathResolver($this->mirrorRoot);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing a timestamp_id');

        $resolver->fileFor($root);
    }
}
