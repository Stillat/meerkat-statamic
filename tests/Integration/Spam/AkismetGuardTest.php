<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Spam;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Exceptions\SpamGuardException;
use Stillat\Meerkat\Guard\Guards\AkismetGuard;
use Stillat\Meerkat\Tests\TestCase;

class AkismetGuardTest extends TestCase
{
    #[Test]
    public function it_throws_a_guard_exception_on_an_http_error_rather_than_reporting_not_spam(): void
    {
        Settings::set('akismet.enabled', true);
        Settings::set('akismet.api_key', 'test-key');
        Settings::set('akismet.blog_url', 'https://example.com');

        Http::fake([
            'https://test-key.rest.akismet.com/1.1/comment-check' => Http::response('error', 500),
        ]);

        $entry = $this->createEntry(['id' => 'akismet-http-error']);
        $comment = $this->createComment([
            'thread_id' => 'akismet-http-error',
            'comment_text' => 'Buy cheap products now',
        ]);

        $this->expectException(SpamGuardException::class);

        app(AkismetGuard::class)->isSpam($entry, $comment);
    }

    #[Test]
    public function it_throws_a_guard_exception_on_an_invalid_response_body(): void
    {
        Settings::set('akismet.enabled', true);
        Settings::set('akismet.api_key', 'test-key');
        Settings::set('akismet.blog_url', 'https://example.com');

        Http::fake([
            'https://test-key.rest.akismet.com/1.1/comment-check' => Http::response('invalid', 200, [
                'X-akismet-debug-help' => 'Empty "blog" value',
            ]),
        ]);

        $entry = $this->createEntry(['id' => 'akismet-invalid-body']);
        $comment = $this->createComment([
            'thread_id' => 'akismet-invalid-body',
            'comment_text' => 'whatever',
        ]);

        $this->expectException(SpamGuardException::class);

        app(AkismetGuard::class)->isSpam($entry, $comment);
    }

    #[Test]
    public function it_checks_spam_without_the_external_package(): void
    {
        Settings::set('akismet.enabled', true);
        Settings::set('akismet.api_key', 'test-key');
        Settings::set('akismet.blog_url', 'https://example.com');

        Http::fake([
            'https://test-key.rest.akismet.com/1.1/comment-check' => Http::response('true', 200),
        ]);

        $entry = $this->createEntry(['id' => 'akismet-entry']);
        $comment = $this->createComment([
            'thread_id' => 'akismet-entry',
            'comment_text' => 'Buy cheap products now',
        ]);

        $guard = app(AkismetGuard::class);

        $this->assertTrue($guard->isSpam($entry, $comment));
    }

    #[Test]
    public function it_submits_spam_and_ham_reports_without_the_external_package(): void
    {
        Settings::set('akismet.enabled', true);
        Settings::set('akismet.api_key', 'test-key');
        Settings::set('akismet.blog_url', 'https://example.com');

        Http::fake([
            'https://test-key.rest.akismet.com/1.1/submit-spam' => Http::response('Thanks', 200),
            'https://test-key.rest.akismet.com/1.1/submit-ham' => Http::response('Thanks', 200),
        ]);

        $entry = $this->createEntry(['id' => 'akismet-reports']);
        $comment = $this->createComment([
            'thread_id' => 'akismet-reports',
            'comment_text' => 'Review my site',
        ]);

        $guard = app(AkismetGuard::class);
        $guard->reportSpam($entry, $comment);
        $guard->reportHam($entry, $comment);

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_omits_user_ip_when_comment_column_is_null(): void
    {
        $this->fakeAkismetCheck();

        $entry = $this->createEntry(['id' => 'akismet-no-ip']);
        $comment = $this->createComment([
            'thread_id' => 'akismet-no-ip',
            'comment_text' => 'no ip stored',
        ]);
        $comment->user_ip = null;
        $comment->save();

        app(AkismetGuard::class)->isSpam($entry, $comment);

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('user_ip', $request->data())
            || $request->data()['user_ip'] === ''
            || $request->data()['user_ip'] === null);
    }

    #[Test]
    public function it_sends_user_ip_user_agent_and_referrer_when_populated_on_comment(): void
    {
        $this->fakeAkismetCheck();

        $entry = $this->createEntry(['id' => 'akismet-with-meta']);
        $comment = $this->createComment([
            'thread_id' => 'akismet-with-meta',
            'comment_text' => 'meta present',
        ]);
        $comment->user_ip = '203.0.113.42';
        $comment->user_agent = 'TestAgent/1.0';
        $comment->referer = 'https://referrer.example.com/post';
        $comment->save();

        app(AkismetGuard::class)->isSpam($entry, $comment);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return ($data['user_ip'] ?? null) === '203.0.113.42'
                && ($data['user_agent'] ?? null) === 'TestAgent/1.0'
                && ($data['referrer'] ?? null) === 'https://referrer.example.com/post';
        });
    }

    private function fakeAkismetCheck(): void
    {
        Settings::set('akismet.enabled', true);
        Settings::set('akismet.api_key', 'test-key');
        Settings::set('akismet.blog_url', 'https://example.com');

        Http::fake([
            'https://test-key.rest.akismet.com/1.1/comment-check' => Http::response('false', 200),
        ]);
    }
}
