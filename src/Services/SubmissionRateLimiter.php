<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Services;

use Illuminate\Support\Facades\RateLimiter;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Extractors\AuthorExtractor;

class SubmissionRateLimiter
{
    public function ensureNotLimited(string $threadId, ?string $email, string $ip): bool
    {
        if (! Settings::get('rate_limits.enabled', true)) {
            return true;
        }

        $maxAttempts = $this->integerSetting('rate_limits.max_attempts', 5);

        return ! RateLimiter::tooManyAttempts($this->compositeKey($threadId, $email, $ip), $maxAttempts)
            && ! RateLimiter::tooManyAttempts($this->ipKey($ip), $maxAttempts);
    }

    public function hit(string $threadId, ?string $email, string $ip): void
    {
        if (! Settings::get('rate_limits.enabled', true)) {
            return;
        }

        $decay = $this->integerSetting('rate_limits.decay_minutes', 15) * 60;

        RateLimiter::hit($this->compositeKey($threadId, $email, $ip), $decay);
        RateLimiter::hit($this->ipKey($ip), $decay);
    }

    public function availableIn(string $threadId, ?string $email, string $ip): int
    {
        return max(
            RateLimiter::availableIn($this->compositeKey($threadId, $email, $ip)),
            RateLimiter::availableIn($this->ipKey($ip)),
        );
    }

    private function compositeKey(string $threadId, ?string $email, string $ip): string
    {
        return implode(':', [
            'meerkat',
            'submit',
            $threadId,
            AuthorExtractor::normalizeEmail($email) ?: 'guest',
            $ip,
        ]);
    }

    private function ipKey(string $ip): string
    {
        return 'meerkat:submit:ip:'.$ip;
    }

    private function integerSetting(string $key, int $default): int
    {
        $value = Settings::get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }
}
