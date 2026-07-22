<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Services\Identity;

use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Extractors\AuthorExtractor;

class IdentityDataResolver
{
    /**
     * @param  array{collection?: ?string, site?: ?string}  $options
     */
    public function resolve(?string $email, ?string $userId, array $options = []): IdentityDataset
    {
        $email = $email ? AuthorExtractor::normalizeEmail($email) : null;

        if (! $email && ! $userId) {
            return new IdentityDataset(email: null, userId: null);
        }

        if ($email && ! $userId) {
            $userId = $this->nullableIdentifier(
                UserMeta::query()->withTrashed()->where('email', $email)->value('user_id')
            );
        }

        if ($userId && ! $email) {
            $storedEmail = UserMeta::query()->withTrashed()->where('user_id', $userId)->value('email');
            $email = is_string($storedEmail) ? AuthorExtractor::normalizeEmail($storedEmail) : null;
        }

        return new IdentityDataset(
            email: $email,
            userId: $userId,
            commentIds: $this->resolveCommentIds($email, $userId, $options),
            revisionIds: $this->resolveRevisionIds($userId),
            moderationAuditIds: $this->resolveModerationAuditIds($userId),
            userMetaIds: $this->resolveUserMetaIds($email, $userId),
        );
    }

    /**
     * @param  array{collection?: ?string, site?: ?string}  $options
     * @return list<int>
     */
    private function resolveCommentIds(?string $email, ?string $userId, array $options): array
    {
        $query = Comment::query()->withTrashed()
            ->where(function ($q) use ($email, $userId) {
                if ($email) {
                    $q->orWhere('comments.author_email', $email);
                }
                if ($userId) {
                    $q->orWhere('comments.author_id', $userId);
                }
            });

        if ($collection = $options['collection'] ?? null) {
            $query->where('comments.collection', $collection);
        }

        if ($site = $options['site'] ?? null) {
            $query->where('comments.site', $site);
        }

        return $this->integerIds($query->pluck('comments.id')->all());
    }

    /**
     * @return list<int>
     */
    private function resolveRevisionIds(?string $userId): array
    {

        if (! $userId) {
            return [];
        }

        return $this->integerIds(CommentRevision::query()->where('edited_by', $userId)->pluck('id')->all());
    }

    /**
     * @return list<int>
     */
    private function resolveModerationAuditIds(?string $userId): array
    {

        if (! $userId) {
            return [];
        }

        return $this->integerIds(CommentModerationAudit::query()->where('actor_id', $userId)->pluck('id')->all());
    }

    /**
     * @return list<int>
     */
    private function resolveUserMetaIds(?string $email, ?string $userId): array
    {
        return $this->integerIds(UserMeta::query()->withTrashed()
            ->where(function ($q) use ($email, $userId) {
                if ($email) {
                    $q->orWhere('email', $email);
                }
                if ($userId) {
                    $q->orWhere('user_id', $userId);
                }
            })
            ->pluck('id')
            ->all());
    }

    private function nullableIdentifier(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) ? (string) $value : null;
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<int>
     */
    private function integerIds(iterable $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $ids[] = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
