<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Concerns;

use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Auth\User;
use Stillat\Meerkat\Contracts\FieldExtractor;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Extractors\AuthorExtractor;
use UnexpectedValueException;

trait ExtractsFields
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{comment: string}&array<string, mixed>
     */
    protected function extractComment(array $data): array
    {
        $extracted = $this->fieldExtractor('comment')->extract($data);

        if (! array_key_exists('comment', $extracted) || ! is_string($extracted['comment']) || trim($extracted['comment']) === '') {
            throw ValidationException::withMessages([
                'comment' => 'The configured Meerkat comment extractor must return a non-empty [comment] value.',
            ]);
        }

        return $extracted;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string|null, email: string|null}
     */
    protected function extractAuthor(array $data): array
    {
        $extracted = $this->fieldExtractor('author')->extract($data);

        foreach (['name', 'email'] as $key) {
            if (! array_key_exists($key, $extracted)) {
                throw ValidationException::withMessages([
                    $key => "The configured Meerkat author extractor must return a [{$key}] value.",
                ]);
            }
        }

        return [
            'name' => AuthorExtractor::normalizeName(is_string($extracted['name']) ? $extracted['name'] : null),
            'email' => AuthorExtractor::normalizeEmail(is_string($extracted['email']) ? $extracted['email'] : null),
        ];
    }

    protected function ensureAuthorMetaDataExists(User $user): void
    {
        $userMeta = UserMeta::withTrashed()->firstOrNew([
            'user_id' => $user->id(),
        ]);

        $details = $this->authorDetailsFromUser($user);

        $userMeta->email = $details['email'] ?? null;
        $userMeta->name = $details['name'] ?? null;
        $userMeta->deleted_at = null;

        $userMeta->saveQuietly();
    }

    /** @return array{name: string|null, email: string|null} */
    protected function authorDetailsFromUser(User $user): array
    {
        $rawEmail = $user->email();
        $email = AuthorExtractor::normalizeEmail(is_string($rawEmail) ? $rawEmail : null);
        $rawName = $user->name();
        $name = AuthorExtractor::normalizeName(is_string($rawName) ? $rawName : null);

        if ($name === null) {
            $rawId = $user->id();
            $identifier = is_string($rawId) || is_int($rawId) ? (string) $rawId : null;
            $name = $email ?? AuthorExtractor::normalizeName($identifier);
        }

        return [
            'name' => $name,
            'email' => $email,
        ];
    }

    private function fieldExtractor(string $type): FieldExtractor
    {
        $extractorClass = config("meerkat.fields.extractors.{$type}");

        if (! is_string($extractorClass)) {
            throw new UnexpectedValueException("The configured Meerkat {$type} extractor must be a class name.");
        }

        $extractor = app($extractorClass);

        if (! $extractor instanceof FieldExtractor) {
            throw new UnexpectedValueException("The configured Meerkat {$type} extractor must implement FieldExtractor.");
        }

        return $extractor;
    }
}
