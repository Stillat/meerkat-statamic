<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Identity;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class AuthorDetailsTest extends TestCase
{
    #[Test]
    public function guest_comments_resolve_their_stored_name_and_email(): void
    {
        $comment = CommentFactory::new()->author('John Doe', 'john@example.com')->create();

        $resolved = Comment::findOrFail($comment->id);
        $this->assertSame('John Doe', $resolved->name);
        $this->assertSame('john@example.com', $resolved->email);
    }

    #[Test]
    public function authenticated_comments_resolve_user_meta_and_discard_stale_guest_fields(): void
    {
        UserMeta::create(['user_id' => 'auth-user', 'name' => 'Jane Smith', 'email' => 'jane@example.com']);
        $comment = CommentFactory::new()->authorId('auth-user')->create(['author_name' => 'Stale', 'author_email' => 'stale@example.com']);

        $fresh = Comment::findOrFail($comment->id);
        $this->assertNull($fresh->author_name);
        $this->assertNull($fresh->author_email);
        $this->assertSame('Jane Smith', $fresh->name);
        $this->assertSame('jane@example.com', $fresh->email);
    }

    #[Test]
    public function missing_or_partial_user_meta_falls_back_per_field(): void
    {
        UserMeta::create(['user_id' => 'null-name', 'name' => null, 'email' => 'named@example.com']);
        UserMeta::create(['user_id' => 'null-email', 'name' => 'Named', 'email' => null]);
        $nullName = CommentFactory::new()->authorId('null-name')->create();
        $nullEmail = CommentFactory::new()->authorId('null-email')->create();
        $missing = CommentFactory::new()->authorId('missing')->create();

        $nullName = Comment::findOrFail($nullName->id);
        $nullEmail = Comment::findOrFail($nullEmail->id);
        $missing = Comment::findOrFail($missing->id);
        $this->assertSame('Anonymous User', $nullName->name);
        $this->assertSame('named@example.com', $nullName->email);
        $this->assertSame('Named', $nullEmail->name);
        $this->assertSame('no-email@example.org', $nullEmail->email);
        $this->assertSame('Anonymous User', $missing->name);
        $this->assertSame('no-email@example.org', $missing->email);
    }

    #[Test]
    public function author_filters_match_guest_and_user_meta_name_and_email(): void
    {
        UserMeta::create(['user_id' => 'meta-user', 'name' => 'Meta Name', 'email' => 'meta@example.com']);
        $guest = CommentFactory::new()->author('Guest Name', 'guest@example.com')->create();
        $meta = CommentFactory::new()->authorId('meta-user')->create();
        CommentFactory::new()->author('Other', 'other@example.com')->create();

        $this->assertSame([$guest->id], Comments::query()->where('name', 'Guest Name')->pluck('comments.id')->all());
        $this->assertSame([$meta->id], Comments::query()->where('name', 'Meta Name')->pluck('comments.id')->all());
        $this->assertSame([$meta->id], Comments::query()->where('email', 'meta@example.com')->pluck('comments.id')->all());
    }

    #[Test]
    public function author_ordering_composes_guest_and_user_meta_names(): void
    {
        UserMeta::create(['user_id' => 'adam', 'name' => 'Adam', 'email' => 'adam@example.com']);
        UserMeta::create(['user_id' => 'zara', 'name' => 'Zara', 'email' => 'zara@example.com']);
        CommentFactory::new()->authorId('zara')->create();
        CommentFactory::new()->author('Mike', 'mike@example.com')->create();
        CommentFactory::new()->authorId('adam')->create();

        $this->assertSame(['Adam', 'Mike', 'Zara'], Comment::orderBy('name')->get()->pluck('name')->all());
    }

    #[Test]
    public function data_array_exposes_resolved_identity(): void
    {
        UserMeta::create(['user_id' => 'array-user', 'name' => 'Array User', 'email' => 'array@example.com']);
        $comment = CommentFactory::new()->authorId('array-user')->create();
        $data = Comment::findOrFail($comment->id)->toDataArray();

        $this->assertSame('Array User', $data['name']);
        $this->assertSame('array@example.com', $data['email']);
    }
}
