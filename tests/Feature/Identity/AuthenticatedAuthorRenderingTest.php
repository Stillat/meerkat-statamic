<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Identity;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class AuthenticatedAuthorRenderingTest extends TestCase
{
    #[Test]
    public function tag_resolves_authenticated_meta_and_guest_identity_tokens(): void
    {
        $this->createEntry(['id' => 'author-render']);
        UserMeta::create(['user_id' => 'auth-user', 'name' => 'Authenticated', 'email' => 'auth@example.com']);
        CommentFactory::new()->threadId('author-render')->authorId('auth-user')->text('Auth body')->data(['comment' => 'Auth body'])->published()->create();
        CommentFactory::new()->threadId('author-render')->author('Guest', 'guest@example.com')->text('Guest body')->data(['comment' => 'Guest body'])->published()->create();

        $result = (string) Antlers::parse('{{ meerkat:comments thread="author-render" }}[{{ name }}|{{ email }}|{{ author_name }}|{{ author_email }}|{{ comment }}]{{ /meerkat:comments }}', [], true);

        $this->assertStringContainsString('[Authenticated|auth@example.com|Authenticated|auth@example.com|Auth body]', $result);
        $this->assertStringContainsString('[Guest|guest@example.com|Guest|guest@example.com|Guest body]', $result);
    }

    #[Test]
    public function missing_authenticated_meta_falls_back_to_anonymous_defaults(): void
    {
        $this->createEntry(['id' => 'author-fallback']);
        CommentFactory::new()->threadId('author-fallback')->authorId('missing-user')->text('Orphan')->data(['comment' => 'Orphan'])->published()->create();

        $result = (string) Antlers::parse('{{ meerkat:comments thread="author-fallback" }}[{{ name }}|{{ email }}]{{ /meerkat:comments }}', [], true);

        $this->assertStringContainsString('[Anonymous User|no-email@example.org]', $result);
    }
}
