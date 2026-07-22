<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class FilesystemSyncEntryHydrationTest extends TestCase
{
    private string $mirrorRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mirrorRoot = $this->temporaryDirectory('meerkat-mirror-hydrate-');
    }

    /** @param array<string, mixed> $frontmatter */
    private function writeComment(string $threadId, string $timestampId, array $frontmatter = [], string $body = 'body'): void
    {
        $dir = $this->mirrorRoot.'/'.$threadId.'/'.$timestampId;
        File::ensureDirectoryExists($dir);

        $defaults = [
            'id' => $timestampId,
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'user_ip' => '127.0.0.1',
            'user_agent' => 'curl/8',
            'referer' => 'http://site.test/post',
            'published' => true,
            'spam' => false,
        ];

        $payload = array_merge($defaults, $frontmatter);
        $yaml = Yaml::dump($payload, 2, 2);

        File::put($dir.'/comment.md', "---\n".$yaml."---\n".$body);
    }

    #[Test]
    public function it_resolves_threads_to_entries_and_propagates_site_collection_title(): void
    {
        $entry = $this->createEntry(['id' => 'entry-blog-1', 'slug' => 'first-post', 'title' => 'First Post']);
        $this->writeComment($entry->id(), '1700000001');

        (new FilesystemSync($this->mirrorRoot))->run();

        $thread = Thread::query()->where('thread_id', $entry->id())->first();
        $this->assertNotNull($thread);
        $this->assertSame($entry->id(), $thread->entry_id);
        $this->assertSame('default', $thread->site);
        $this->assertSame('blog', $thread->collection);
        $this->assertSame('First Post', $thread->cached_title);

        $comment = Comment::query()->where('timestamp_id', '1700000001')->first();
        $this->assertNotNull($comment);
        $this->assertSame('default', $comment->site);
        $this->assertSame('blog', $comment->collection);
    }

    #[Test]
    public function it_restores_moderation_state_from_the_mirror_on_rebuild(): void
    {
        $this->writeComment('mod-thread', '1700000020', [
            'published' => false,
            'spam' => false,
            'ham' => true,
            'checked_for_spam' => true,
            'moderation_status' => 'rejected',
            'moderation_reason' => 'off-topic',
            'moderation_notes' => 'handled by jane',
            'moderated_by' => 'mod-user',
            'moderated_at' => 1700000099,
        ]);

        (new FilesystemSync($this->mirrorRoot))->run();

        $comment = Comment::query()->where('timestamp_id', '1700000020')->first();
        $this->assertNotNull($comment);
        $this->assertFalse($comment->is_published);
        $this->assertTrue($comment->is_ham);
        $this->assertTrue($comment->checked_for_spam);
        $this->assertSame('rejected', $comment->moderation_status);
        $this->assertSame('off-topic', $comment->moderation_reason);
        $this->assertSame('handled by jane', $comment->moderation_notes);
        $this->assertSame('mod-user', $comment->moderated_by);
    }

    #[Test]
    public function it_imports_without_an_entry_and_keeps_thread_metadata_blank(): void
    {

        $this->writeComment('orphan-thread-id', '1700000010');

        $result = (new FilesystemSync($this->mirrorRoot))->run();

        $this->assertSame(0, $result['stats']['threads_resolved']);

        $thread = Thread::query()->where('thread_id', 'orphan-thread-id')->first();
        $this->assertNotNull($thread);
        $this->assertNull($thread->entry_id);
        $this->assertNull($thread->site);
        $this->assertNull($thread->collection);

        $this->assertSame('orphan-thread-id', $thread->cached_title);
    }

    #[Test]
    public function it_links_authenticated_comments_to_an_existing_users_meta_row(): void
    {

        $role = $this->makeStatamicRole();
        $role->handle('reader-r1');
        $role->title('Reader R1');
        $role->permissions([]);
        $role->save();
        $user = $this->makeStatamicUser();
        $user->id('user-77');
        $user->email('authed@example.com');
        $user->set('name', 'Authed User');
        $user->save();
        $user->assignRole($role);
        $user->saveQuietly();

        $entry = $this->createEntry(['id' => 'entry-auth', 'slug' => 'auth-post', 'title' => 'Auth Post']);
        $this->writeComment($entry->id(), '1700000020', [
            'name' => 'Authed User',
            'email' => 'authed@example.com',
            'authenticated_user' => 'user-77',
        ]);

        (new FilesystemSync($this->mirrorRoot))->run();

        $comment = Comment::query()->where('timestamp_id', '1700000020')->first();
        $this->assertNotNull($comment);
        $this->assertSame('user-77', $comment->author_id);

        $meta = UserMeta::query()->where('user_id', 'user-77')->first();
        $this->assertNotNull($meta);
        $this->assertSame('Authed User', $meta->name);
        $this->assertSame('authed@example.com', $meta->email);
    }

    #[Test]
    public function users_meta_backfill_falls_back_to_frontmatter_when_the_statamic_user_is_missing(): void
    {

        $entry = $this->createEntry(['id' => 'entry-ghost', 'slug' => 'ghost-post', 'title' => 'Ghost Post']);
        $this->writeComment($entry->id(), '1700000030', [
            'name' => 'Departed Soul',
            'email' => 'gone@example.com',
            'authenticated_user' => 'ghost-user',
        ]);

        (new FilesystemSync($this->mirrorRoot))->run();

        $meta = UserMeta::query()->where('user_id', 'ghost-user')->first();
        $this->assertNotNull($meta);
        $this->assertSame('Departed Soul', $meta->name);
        $this->assertSame('gone@example.com', $meta->email);
    }

    #[Test]
    public function users_meta_is_only_created_once_per_user_across_many_comments(): void
    {
        $entry = $this->createEntry(['id' => 'entry-dup', 'slug' => 'dup-post', 'title' => 'Dup Post']);
        $this->writeComment($entry->id(), '1700000040', [
            'name' => 'Repeat',
            'email' => 'repeat@example.com',
            'authenticated_user' => 'repeater',
        ]);
        $this->writeComment($entry->id(), '1700000041', [
            'name' => 'Repeat',
            'email' => 'repeat@example.com',
            'authenticated_user' => 'repeater',
        ]);
        $this->writeComment($entry->id(), '1700000042', [
            'name' => 'Repeat',
            'email' => 'repeat@example.com',
            'authenticated_user' => 'repeater',
        ]);

        $result = (new FilesystemSync($this->mirrorRoot))->run();

        $this->assertSame(1, $result['stats']['users_meta_created']);
        $this->assertSame(1, UserMeta::query()->where('user_id', 'repeater')->count());
    }

    #[Test]
    public function rerunning_the_sync_does_not_overwrite_a_resolved_cached_title_when_the_entry_disappears(): void
    {
        $entry = $this->createEntry(['id' => 'entry-vanishing', 'slug' => 'will-vanish', 'title' => 'Original Title']);
        $this->writeComment($entry->id(), '1700000050');
        (new FilesystemSync($this->mirrorRoot))->run();

        $this->assertSame('Original Title', Thread::query()->where('thread_id', $entry->id())->value('cached_title'));

        $entry->delete();

        (new FilesystemSync($this->mirrorRoot))->run();

        $this->assertSame('Original Title', Thread::query()->where('thread_id', 'entry-vanishing')->value('cached_title'));
    }
}
