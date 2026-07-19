<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Identity;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Support\ContextSigner;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class PrivacyTogglesTest extends TestCase
{
    #[Test]
    public function request_metadata_is_captured_by_default(): void
    {
        $this->submit('privacy-default', 'default capture')->assertRedirect();
        $comment = Comment::query()->where('comment_text', 'default capture')->firstOrFail();

        $this->assertNotEmpty($comment->user_ip);
        $this->assertSame('PrivacyTest/1.0', $comment->user_agent);
        $this->assertSame('https://example.com/blog/post', $comment->referer);
    }

    #[Test]
    public function each_privacy_toggle_independently_suppresses_only_its_metadata(): void
    {
        foreach ([
            ['meerkat.privacy.store_user_ip', 'user_ip'],
            ['meerkat.privacy.store_user_agent', 'user_agent'],
            ['meerkat.privacy.store_referrer', 'referer'],
        ] as $index => [$setting, $attribute]) {
            config()->set($setting, false);
            $body = 'privacy toggle '.$index;
            $this->submit('privacy-'.$index, $body)->assertRedirect();
            $comment = Comment::query()->where('comment_text', $body)->firstOrFail();
            $this->assertNull($comment->{$attribute});

            foreach (['user_ip', 'user_agent', 'referer'] as $other) {
                if ($other !== $attribute) {
                    $this->assertNotEmpty($comment->{$other});
                }
            }
            config()->set($setting, true);
        }

        config()->set('meerkat.privacy.store_user_ip', false);
        config()->set('meerkat.privacy.store_user_agent', false);
        config()->set('meerkat.privacy.store_referrer', false);
        $this->submit('privacy-none', 'privacy none')->assertRedirect();
        $none = Comment::query()->where('comment_text', 'privacy none')->firstOrFail();
        $this->assertNull($none->user_ip);
        $this->assertNull($none->user_agent);
        $this->assertNull($none->referer);
    }

    /** @return TestResponse<Response> */
    private function submit(string $thread, string $body): TestResponse
    {
        $this->createEntry(['id' => $thread]);

        return $this->withHeaders(['User-Agent' => 'PrivacyTest/1.0', 'Referer' => 'https://example.com/blog/post'])
            ->post(route('meerkat.comment-create'), [
                '_meerkat_context' => $thread,
                '_meerkat_context_signature' => ContextSigner::sign($thread),
                'comment' => $body,
                'name' => 'Tester',
                'email' => 'test@example.com',
            ]);
    }
}
