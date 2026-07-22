<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Facades;

use Illuminate\Support\Facades\Facade;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\CommentQueryBuilder;
use Stillat\Meerkat\Database\Models\Comment;

/**
 * @method static void ensureThreadExists(Entry $entry)
 * @method static bool deleteComment(int $id, ?string $reason = null)
 * @method static int removeSubtree(int $id, ?string $reason = null)
 * @method static bool restoreComment(int $id)
 * @method static bool forceDeleteComment(int $id)
 * @method static list<int> hiddenSubtreeIds(string $threadId, bool $includeTombstones = false, bool $includeReplies = false)
 * @method static list<int> hiddenSubtreeIdsForThreads(?list<string> $threadIds, bool $includeTombstones = false, bool $includeReplies = false)
 * @method static bool markAsSpam(int $id)
 * @method static bool markAsHam(int $id)
 * @method static bool reject(int $id, ?string $reason = null, ?string $notes = null)
 * @method static bool publish(int $id)
 * @method static bool unpublish(int $id)
 * @method static bool areCommentsEnabledForEntry(Entry $entry)
 * @method static void checkForSpam(int $id)
 * @method static void checkOutstandingForSpam()
 * @method static Comment inReplyTo(Comment $parent)
 * @method static Entry getCommentEntry(Comment $comment)
 * @method static list<array<string, mixed>> thread(string $threadId, bool $publishedOnly = true)
 * @method static \Statamic\Extensions\Pagination\LengthAwarePaginator rootsForThread(string $threadId, int $perPage = 15, bool $publishedOnly = true)
 * @method static list<array<string, mixed>> userHistory(string|int $identifier, int $limit = 50)
 * @method static list<array<string, mixed>> recentActivity(int $limit = 50, bool $publishedOnly = true)
 * @method static list<array<string, mixed>> moderationQueue(int $limit = 50)
 * @method static list<array<string, mixed>> spamQueue(int $limit = 50)
 * @method static int bulkApprove(list<int> $ids)
 * @method static int bulkSpam(list<int> $ids)
 * @method static int bulkReject(list<int> $ids, ?string $reason = null)
 * @method static int bulkDelete(list<int> $ids)
 * @method static Comment|null withAncestry(int $commentId)
 * @method static list<array<string, mixed>> search(string $term, int $limit = 50)
 */
class Comments extends Facade
{
    protected static function getFacadeAccessor()
    {
        return CommentRepository::class;
    }

    public static function query(): CommentQueryBuilder
    {
        return app(CommentQueryBuilder::class);
    }
}
