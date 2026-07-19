<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Statamic\Facades\Entry;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Database\CommentQueryBuilder;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Http\Resources\Comments\PublicCommentResource;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Services\ThreadResolver;
use Stillat\Meerkat\Support\CommentVisibility;
use Stillat\Meerkat\Support\Features;

class ThreadController extends Controller
{
    use GetsMeerkatConfig;

    public function __construct(
        private readonly ThreadResolver $threads,
        private readonly CommentVisibility $visibility,
    ) {}

    public function thread(Request $request, string $threadId, ThreadMetricsManager $metrics): JsonResponse
    {
        $threadId = $this->resolveThreadId($threadId);

        return response()->json([
            'thread' => Thread::query()->where('thread_id', $threadId)->first()?->toArray(),
            'metrics' => $this->metricArrayForRequest($request, $threadId, $metrics),
        ]);
    }

    public function entryThread(Request $request, string $entryId, ThreadMetricsManager $metrics): JsonResponse
    {
        $this->ensureApiEnabled();
        $entry = Entry::findOrFail($entryId);

        return $this->thread($request, $this->threads->forEntry($entry), $metrics);
    }

    public function comments(Request $request, string $threadId): JsonResponse
    {
        $threadId = $this->resolveThreadId($threadId);
        $includeUnpublished = $request->boolean('include_unpublished') && $this->canSeeUnpublished($threadId);
        [$includeTombstones, $includeReplies] = $this->resolveTombstoneInclusion($request, $threadId);
        $includeRemoved = $includeTombstones || $includeReplies;
        $hidden = Comments::hiddenSubtreeIds($threadId, $includeTombstones, $includeReplies);

        $query = $this->visibleCommentsQuery($threadId, $includeUnpublished, $hidden, $includeRemoved)
            ->roots()
            ->hierarchical();
        $visibleCount = $this->visibleCommentsQuery($threadId, $includeUnpublished, $hidden, $includeRemoved);

        $maxComments = $this->resolveFullThreadCommentLimit();

        if ($maxComments > 0 && $visibleCount->count() > $maxComments) {
            return response()->json([
                'message' => __('meerkat::validation.full_thread_too_large'),
                'max_comments' => $maxComments,
            ], 413);
        }

        $roots = $query->with(['allChildren' => $this->childrenEagerConstraint($hidden, $includeUnpublished, $includeRemoved)])->get();

        return response()->json([
            'thread_id' => $threadId,
            'comments' => PublicCommentResource::collection($this->flatten($roots))->resolve($request),
        ]);
    }

    /**
     * @param  list<int>  $hidden
     */
    private function childrenEagerConstraint(array $hidden, bool $includeUnpublished, bool $includeRemoved = false): \Closure
    {
        return function (Relation $relation) use ($hidden, $includeUnpublished, $includeRemoved): void {
            $query = $relation->getQuery();

            if (! $includeUnpublished) {
                $query->where('is_published', true)->where('is_spam', false);

                if (! $includeRemoved) {
                    $query->where('is_removed', false);
                }
            }

            if ($hidden !== []) {
                $query->whereNotIn('comments.id', $hidden);
            }
            $query->with(['allChildren' => $this->childrenEagerConstraint($hidden, $includeUnpublished, $includeRemoved)]);
        };
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function resolveTombstoneInclusion(Request $request, string $threadId): array
    {
        if (! $this->visibility->canViewModerationForThread($threadId)) {
            return [false, false];
        }

        return [
            $request->boolean('include_tombstones'),
            $request->boolean('include_tombstone_replies'),
        ];
    }

    public function entryComments(Request $request, string $entryId): JsonResponse
    {
        $this->ensureApiEnabled();
        $entry = Entry::findOrFail($entryId);

        return $this->comments($request, $this->threads->forEntry($entry));
    }

    public function roots(Request $request, string $threadId): AnonymousResourceCollection
    {
        $threadId = $this->resolveThreadId($threadId);
        $perPage = $this->resolveApiPerPage($request->integer('per_page'));
        $includeUnpublished = $request->boolean('include_unpublished') && $this->canSeeUnpublished($threadId);
        [$includeTombstones, $includeReplies] = $this->resolveTombstoneInclusion($request, $threadId);
        $hidden = Comments::hiddenSubtreeIds($threadId, $includeTombstones, $includeReplies);

        $query = $this->visibleCommentsQuery($threadId, $includeUnpublished, $hidden, $includeTombstones || $includeReplies)
            ->roots()
            ->hierarchical();

        $paginator = $query->paginate($perPage);

        return PublicCommentResource::collection($paginator);
    }

    public function entryRoots(Request $request, string $entryId): AnonymousResourceCollection
    {
        $this->ensureApiEnabled();
        $entry = Entry::findOrFail($entryId);

        return $this->roots($request, $this->threads->forEntry($entry));
    }

    public function children(Request $request, string $threadId, int $commentId): AnonymousResourceCollection
    {
        $threadId = $this->resolveThreadId($threadId);
        $perPage = $this->resolveApiPerPage($request->integer('per_page'));
        $includeUnpublished = $request->boolean('include_unpublished') && $this->canSeeUnpublished($threadId);
        [$includeTombstones, $includeReplies] = $this->resolveTombstoneInclusion($request, $threadId);
        $hidden = Comments::hiddenSubtreeIds($threadId, $includeTombstones, $includeReplies);

        $query = $this->visibleCommentsQuery($threadId, $includeUnpublished, $hidden, $includeTombstones || $includeReplies)
            ->where('parent_id', $commentId)
            ->orderBy('comments.created_at')
            ->orderBy('comments.id');

        $paginator = $query->paginate($perPage);

        return PublicCommentResource::collection($paginator);
    }

    public function stats(Request $request, string $threadId, ThreadMetricsManager $metrics): JsonResponse
    {
        $threadId = $this->resolveThreadId($threadId);

        return response()->json($this->metricArrayForRequest($request, $threadId, $metrics));
    }

    public function entryStats(Request $request, string $entryId, ThreadMetricsManager $metrics): JsonResponse
    {
        $this->ensureApiEnabled();
        $entry = Entry::findOrFail($entryId);

        return $this->stats($request, $this->threads->forEntry($entry), $metrics);
    }

    public function history(int $commentId): JsonResponse
    {
        $this->ensureApiEnabled();
        $comment = Comment::query()->findOrFail($commentId);

        abort_unless($this->visibility->canViewModerationForComment($comment), 403);

        return response()->json([
            'audits' => CommentModerationAudit::query()
                ->where('comment_id', $commentId)
                ->latest()
                ->get()
                ->toArray(),
        ]);
    }

    public function revisions(int $commentId): JsonResponse
    {
        $this->ensureApiEnabled();
        abort_unless(Features::revisions(), 404);
        $comment = Comment::query()->findOrFail($commentId);

        abort_unless($this->visibility->canViewModerationForComment($comment), 403);

        return response()->json([
            'revisions' => CommentRevision::query()
                ->where('comment_id', $commentId)
                ->orderByDesc('revision_number')
                ->get()
                ->toArray(),
        ]);
    }

    public function recent(Request $request): AnonymousResourceCollection
    {
        $this->ensureApiEnabled();

        $limit = $this->resolveApiLimit($request->integer('limit'), 25);
        $query = Comments::query()->newest();
        $this->visibility->applyAccessibleScope($query);
        $comments = $query->limit($limit)->get();

        return PublicCommentResource::collection($comments);
    }

    public function authorHistory(Request $request, string $identifier): AnonymousResourceCollection
    {
        $this->ensureApiEnabled();

        $limit = $this->resolveApiLimit($request->integer('limit'), 50);
        $query = Comments::query()->byAuthor($identifier)->newest();
        $this->visibility->applyAccessibleScope($query);
        $comments = $query->limit($limit)->get();

        return PublicCommentResource::collection($comments);
    }

    public function search(Request $request): AnonymousResourceCollection
    {
        $this->ensureApiEnabled();

        $validated = $this->stringKeyedArray($request->validate([
            'q' => ['required', 'string', 'min:2'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]));

        $limitValue = $validated['limit'] ?? null;
        $limit = $this->resolveApiLimit(is_int($limitValue) ? $limitValue : 0, 25);
        $queryValue = $validated['q'] ?? null;
        $term = '%'.(is_string($queryValue) ? $queryValue : '').'%';

        $query = Comments::query()
            ->where('comment_text', 'like', $term)
            ->newest();
        $this->visibility->applyAccessibleScope($query);
        $comments = $query->limit($limit)->get();

        return PublicCommentResource::collection($comments);
    }

    private function ensureApiEnabled(): void
    {
        abort_unless($this->apiEnabled(), 404);
    }

    private function resolveThreadId(string $threadId): string
    {
        $this->ensureApiEnabled();

        if (Thread::query()->where('thread_id', $threadId)->exists()) {
            return $threadId;
        }

        $entry = Entry::find($threadId);

        abort_unless($entry !== null, 404);

        return $this->threads->forEntry($entry);
    }

    private function resolveApiLimit(int $limit, int $default): int
    {
        if ($limit <= 0) {
            return $default;
        }

        return min($limit, $this->integerConfig('meerkat.api.max_per_page', 100));
    }

    private function resolveFullThreadCommentLimit(): int
    {
        return max(0, $this->integerConfig('meerkat.api.max_full_thread_comments', 500));
    }

    private function canSeeUnpublished(string $threadId): bool
    {
        return $this->visibility->canViewModerationForThread($threadId);
    }

    /**
     * @param  list<int>  $hidden
     */
    private function visibleCommentsQuery(string $threadId, bool $includeUnpublished, array $hidden, bool $includeRemoved = false): CommentQueryBuilder
    {
        $query = Comments::query()->forThread($threadId);

        if (! $includeUnpublished) {

            $query->published()->where('is_spam', false);

            if (! $includeRemoved) {
                $query->where('comments.is_removed', false);
            }
        }

        if ($hidden !== []) {
            $query->whereNotIn('comments.id', $hidden);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function metricArrayForRequest(Request $request, string $threadId, ThreadMetricsManager $metrics): array
    {
        if ($request->boolean('include_moderation') && $this->visibility->canViewModerationForThread($threadId)) {
            return $this->stringKeyedArray($metrics->getThreadMetric($threadId)->toArray());
        }

        return $this->visibility->publicMetricArray($threadId);
    }

    /**
     * @param  iterable<Comment>  $roots
     * @return list<Comment>
     */
    private function flatten(iterable $roots): array
    {
        $out = [];

        $walk = function (iterable $items) use (&$walk, &$out): void {
            foreach ($items as $item) {
                if (! $item instanceof Comment) {
                    continue;
                }

                $out[] = $item;

                if ($item->relationLoaded('allChildren')) {
                    $children = $item->getRelation('allChildren');

                    if (is_iterable($children)) {
                        $walk($children);
                    }
                }
            }
        };

        $walk($roots);

        return $out;
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
