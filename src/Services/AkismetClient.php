<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Exceptions\SpamGuardException;

class AkismetClient
{
    public function enabled(): bool
    {
        return Settings::get('akismet.enabled', true)
            && filled(Settings::get('akismet.api_key'))
            && filled(Settings::get('akismet.blog_url'));
    }

    /**
     * @throws SpamGuardException When Akismet cannot be reached or returns a
     *                            response that is not a clear true/false verdict.
     */
    /** @param array<string, mixed> $payload */
    public function commentCheck(array $payload): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $response = $this->request('comment-check', $payload);

        $body = trim((string) $response->body());

        if ($body !== 'true' && $body !== 'false') {
            throw new SpamGuardException(
                'Akismet returned an unexpected comment-check response: '
                .($body === '' ? '(empty body)' : $body)
                .($response->header('X-akismet-debug-help') !== ''
                    ? ': '.$response->header('X-akismet-debug-help')
                    : '')
            );
        }

        return $body === 'true';
    }

    /** @param array<string, mixed> $payload */
    public function submitSpam(array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->request('submit-spam', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function submitHam(array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->request('submit-ham', $payload);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $path, array $payload): Response
    {
        $apiKey = Settings::get('akismet.api_key');
        $host = config('meerkat.akismet.api_host', 'rest.akismet.com');

        if (! is_string($apiKey) || $apiKey === '' || ! is_string($host) || $host === '') {
            throw new SpamGuardException('Akismet requires a valid API key and API host.');
        }

        try {
            return Http::asForm()
                ->withHeaders(['Accept' => 'text/plain'])
                ->connectTimeout(5)
                ->timeout(10)
                ->post("https://{$apiKey}.{$host}/1.1/{$path}", array_merge([
                    'blog' => Settings::get('akismet.blog_url'),
                ], array_filter($payload, fn ($value) => $value !== null && $value !== '')))
                ->throw();
        } catch (RequestException $e) {
            throw new SpamGuardException("Could not reach Akismet ({$path}): HTTP {$e->response->status()}. ".str_replace($apiKey, '[redacted]', $e->getMessage()));
        } catch (ConnectionException $e) {
            throw new SpamGuardException("Could not reach Akismet ({$path}): connection failed. ".str_replace($apiKey, '[redacted]', $e->getMessage()));
        }
    }
}
