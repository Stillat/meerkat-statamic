<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Guard;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Exceptions\SpamGuardException;
use Stillat\Meerkat\Guard\Guards\AkismetGuard;
use Stillat\Meerkat\Guard\Manager;
use Stillat\Meerkat\Services\AkismetClient;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ReportSpamResilienceTest extends TestCase
{
    #[Test]
    public function manager_suppresses_report_failures_for_spam_and_ham_without_aborting_other_guards(): void
    {
        ThrowingSpamGuard::$spamReports = ThrowingSpamGuard::$hamReports = RecordingSpamGuard::$spamReports = 0;
        config(['meerkat.spam.guards' => [ThrowingSpamGuard::class, RecordingSpamGuard::class]]);
        $entry = $this->createEntry(['id' => 'resilience']);
        $comment = CommentFactory::new()->threadId('resilience')->create();

        app(Manager::class)->reportSpam($entry, $comment);
        app(Manager::class)->reportHam($entry, $comment);

        $this->assertSame(1, ThrowingSpamGuard::$spamReports);
        $this->assertSame(1, ThrowingSpamGuard::$hamReports);
        $this->assertSame(1, RecordingSpamGuard::$spamReports);
    }

    #[Test]
    public function failing_akismet_submission_is_sent_but_does_not_escape_the_manager(): void
    {
        $this->configureAkismet('test-key');
        config(['meerkat.spam.guards' => [AkismetGuard::class]]);
        Http::fake(['https://test-key.rest.akismet.com/1.1/submit-spam' => Http::response('error', 500)]);

        app(Manager::class)->reportSpam($this->createEntry(['id' => 'akismet-resilience']), CommentFactory::new()->threadId('akismet-resilience')->create());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://test-key.rest.akismet.com/1.1/submit-spam' && $request->method() === 'POST');
    }

    #[Test]
    public function akismet_exception_redacts_the_api_key(): void
    {
        $this->configureAkismet('super-secret-key');
        Http::fake(['https://super-secret-key.rest.akismet.com/1.1/submit-spam' => Http::response('error', 500)]);

        try {
            app(AkismetClient::class)->submitSpam(['comment_content' => 'spam']);
            $this->fail('Expected a SpamGuardException.');
        } catch (SpamGuardException $exception) {
            $this->assertStringNotContainsString('super-secret-key', $exception->getMessage());
        }
    }

    private function configureAkismet(string $key): void
    {
        Settings::set('akismet.enabled', true);
        Settings::set('akismet.api_key', $key);
        Settings::set('akismet.blog_url', 'https://example.com');
    }
}

class ThrowingSpamGuard implements SpamGuard
{
    public static int $spamReports = 0;

    public static int $hamReports = 0;

    public function isSpam(Entry $entry, Comment $comment): bool
    {
        return false;
    }

    public function reportSpam(Entry $entry, Comment $comment): void
    {
        self::$spamReports++;
        throw new RuntimeException('failure');
    }

    public function reportHam(Entry $entry, Comment $comment): void
    {
        self::$hamReports++;
        throw new RuntimeException('failure');
    }
}

class RecordingSpamGuard implements SpamGuard
{
    public static int $spamReports = 0;

    public function isSpam(Entry $entry, Comment $comment): bool
    {
        return false;
    }

    public function reportSpam(Entry $entry, Comment $comment): void
    {
        self::$spamReports++;
    }

    public function reportHam(Entry $entry, Comment $comment): void {}
}
