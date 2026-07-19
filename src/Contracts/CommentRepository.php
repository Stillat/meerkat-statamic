<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Contracts;

use Statamic\Contracts\Entries\Entry;
use Statamic\Extensions\Pagination\LengthAwarePaginator;
use Stillat\Meerkat\Database\Models\Comment;

interface CommentRepository
{
    public function ensureThreadExists(Entry $entry): void;

    public function deleteComment(int $id, ?string $reason = null): bool;

    public function removeSubtree(int $id, ?string $reason = null): int;

    public function restoreComment(int $id): bool;

    public function forceDeleteComment(int $id): bool;

    /**
     * @return list<int>
     */
    public function hiddenSubtreeIds(string $threadId, bool $includeTombstones = false, bool $includeReplies = false): array;

    /**
     * @param  list<string>|null  $threadIds
     * @return list<int>
     */
    public function hiddenSubtreeIdsForThreads(?array $threadIds, bool $includeTombstones = false, bool $includeReplies = false): array;

    public function markAsSpam(int $id): bool;

    public function markAsHam(int $id): bool;

    public function reject(int $id, ?string $reason = null, ?string $notes = null): bool;

    public function publish(int $id): bool;

    public function unpublish(int $id): bool;

    public function restoreRevision(int $commentId, int $revisionNumber): bool;

    public function areCommentsEnabledForEntry(Entry $entry): bool;

    public function isExplicitlyDisabledForEntry(Entry $entry): bool;

    public function checkForSpam(int $id): void;

    public function checkOutstandingForSpam(): void;

    public function inReplyTo(Comment $parent): Comment;

    public function getCommentEntry(Comment $comment): ?Entry;

    /** @return list<array<string, mixed>> */
    public function thread(string $threadId, bool $publishedOnly = true): array;

    public function rootsForThread(string $threadId, int $perPage = 15, bool $publishedOnly = true): LengthAwarePaginator;

    /** @return list<array<string, mixed>> */
    public function userHistory(string|int $identifier, int $limit = 50): array;

    /** @return list<array<string, mixed>> */
    public function recentActivity(int $limit = 50, bool $publishedOnly = true): array;

    /** @return list<array<string, mixed>> */
    public function moderationQueue(int $limit = 50): array;

    /** @return list<array<string, mixed>> */
    public function spamQueue(int $limit = 50): array;

    /** @param list<int> $ids */
    public function bulkApprove(array $ids): int;

    /** @param list<int> $ids */
    public function bulkSpam(array $ids): int;

    /** @param list<int> $ids */
    public function bulkReject(array $ids, ?string $reason = null): int;

    /** @param list<int> $ids */
    public function bulkDelete(array $ids): int;

    public function withAncestry(int $commentId): ?Comment;

    /** @return list<array<string, mixed>> */
    public function search(string $term, int $limit = 50): array;
}
