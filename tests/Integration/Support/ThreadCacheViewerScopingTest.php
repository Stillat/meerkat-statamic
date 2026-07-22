<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Support\ThreadCache;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ThreadCacheViewerScopingTest extends TestCase
{
    #[Test]
    public function a_moderator_primed_cache_entry_is_not_served_to_guests(): void
    {
        $this->createEntry(['id' => 'viewer-cache']);
        CommentFactory::new()->threadId('viewer-cache')->published()->create();

        $this->makeAdmin('viewer-cache-admin');
        $moderator = $this->requireRows($this->repo()->thread('viewer-cache'));
        $this->assertTrue($this->requireObject($moderator[0]['current_user'])['is_authenticated']);

        auth()->logout();

        $guest = $this->requireRows($this->repo()->thread('viewer-cache'));
        $this->assertFalse($this->requireObject($guest[0]['current_user'])['is_authenticated']);
    }

    #[Test]
    public function invalidation_clears_every_viewer_variant(): void
    {
        $this->createEntry(['id' => 'viewer-cache-invalidation']);
        CommentFactory::new()->threadId('viewer-cache-invalidation')->published()->create();

        $this->assertCount(1, $this->repo()->thread('viewer-cache-invalidation'));

        CommentFactory::new()->threadId('viewer-cache-invalidation')->published()->create();

        $this->assertCount(2, $this->repo()->thread('viewer-cache-invalidation'));
    }

    #[Test]
    public function keys_are_viewer_specific_and_invalidation_rotates_them(): void
    {
        $guestKey = ThreadCache::key('key-thread', true);

        $this->makeAdmin('viewer-cache-key-admin');
        $adminKey = ThreadCache::key('key-thread', true);
        $this->assertNotSame($guestKey, $adminKey);

        ThreadCache::invalidate('key-thread');
        $this->assertNotSame($adminKey, ThreadCache::key('key-thread', true));
    }
}
