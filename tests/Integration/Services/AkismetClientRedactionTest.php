<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Services;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Exceptions\SpamGuardException;
use Stillat\Meerkat\Services\AkismetClient;
use Stillat\Meerkat\Tests\TestCase;
use Throwable;

class AkismetClientRedactionTest extends TestCase
{
    private const KEY = 'super-secret-akismet-key';

    protected function setUp(): void
    {
        parent::setUp();

        Settings::set('akismet.enabled', true);
        Settings::set('akismet.api_key', self::KEY);
        Settings::set('akismet.blog_url', 'https://example.com');
    }

    #[Test]
    public function connection_failures_never_expose_the_api_key_and_use_short_timeouts(): void
    {
        $captured = null;

        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured = $options;

            throw new ConnectException(
                'cURL error 28: Connection timed out for https://'.self::KEY.'.rest.akismet.com/1.1/comment-check',
                $request->toPsrRequest()
            );
        });

        try {
            app(AkismetClient::class)->commentCheck(['comment_content' => 'hello']);
            $this->fail('Expected a SpamGuardException.');
        } catch (SpamGuardException $exception) {
            $this->assertStringContainsString('[redacted]', $exception->getMessage());

            for ($link = $exception; $link instanceof Throwable; $link = $link->getPrevious()) {
                $this->assertStringNotContainsString(self::KEY, $link->getMessage());
            }

            $this->assertNull($exception->getPrevious(), 'The raw client exception must not be chained.');
        }

        $this->assertIsArray($captured);
        $this->assertSame(5, $captured['connect_timeout']);
        $this->assertSame(10, $captured['timeout']);
    }

    #[Test]
    public function http_error_failures_never_expose_the_api_key_in_the_exception_chain(): void
    {
        Http::fake([
            'https://'.self::KEY.'.rest.akismet.com/*' => Http::response('error', 500),
        ]);

        try {
            app(AkismetClient::class)->commentCheck(['comment_content' => 'hello']);
            $this->fail('Expected a SpamGuardException.');
        } catch (SpamGuardException $exception) {
            for ($link = $exception; $link instanceof Throwable; $link = $link->getPrevious()) {
                $this->assertStringNotContainsString(self::KEY, $link->getMessage());
            }

            $this->assertNull($exception->getPrevious(), 'The raw client exception must not be chained.');
        }
    }
}
