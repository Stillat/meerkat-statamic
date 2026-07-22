<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Database\QueryBuilder;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class AuthorScopesTest extends TestCase
{
    #[Test]
    public function author_scopes_resolve_ids_guest_emails_and_authenticated_email_metadata(): void
    {
        UserMeta::create([
            'user_id' => 'member',
            'email' => 'member@example.com',
            'name' => 'Member',
        ]);

        $member = CommentFactory::new()->create([
            'thread_id' => 'authors',
            'author_id' => 'member',
            'author_email' => null,
        ]);
        $numeric = CommentFactory::new()->create(['thread_id' => 'authors', 'author_id' => 42]);
        $guest = CommentFactory::new()->create([
            'thread_id' => 'authors',
            'author_id' => null,
            'author_email' => 'guest@example.com',
        ]);
        CommentFactory::new()->create(['thread_id' => 'authors', 'author_id' => 99]);

        $query = fn () => Comments::query()->forThread('authors');

        $this->assertSame([$member->id], $query()->byAuthor('member@example.com')->pluck('id')->all());
        $this->assertSame([$numeric->id], $query()->byAuthor(42)->pluck('id')->all());
        $this->assertSame([$guest->id], $query()->byAuthor('guest@example.com')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$numeric->id, $guest->id],
            $query()->byAuthors([42, 'guest@example.com'])->pluck('id')->all(),
        );
    }

    #[Test]
    public function authenticated_and_guest_scopes_partition_comments(): void
    {
        $authenticated = CommentFactory::new()->create(['thread_id' => 'partition', 'author_id' => 1]);
        $guest = CommentFactory::new()->create(['thread_id' => 'partition', 'author_id' => null]);

        $query = fn () => Comments::query()->forThread('partition');

        $this->assertSame([$authenticated->id], $query()->authenticated()->pluck('id')->all());
        $this->assertSame([$guest->id], $query()->guests()->pluck('id')->all());
    }
}
