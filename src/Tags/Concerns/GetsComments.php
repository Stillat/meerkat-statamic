<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tags\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Statamic\Extensions\Pagination\LengthAwarePaginator;
use Statamic\Facades\Site;
use Statamic\Hooks\Payload;
use Statamic\Tags\Concerns\GetsQuerySelectKeys;
use Statamic\Tags\Concerns\OutputsItems;
use Statamic\Tags\Concerns\QueriesConditions;
use Statamic\Tags\Concerns\QueriesOrderBys;
use Statamic\Tags\Concerns\QueriesScopes;
use Stillat\Meerkat\Comments\CommentNode;
use Stillat\Meerkat\Comments\ThreadBuilder;
use Stillat\Meerkat\Database\CommentQueryBuilder;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Support\CommentVisibility;

trait GetsComments
{
    use GetsQuerySelectKeys,
        OutputsItems,
        QueriesConditions,
        QueriesOrderBys,
        QueriesScopes;

    protected function getComments(): mixed
    {
        $query = $this->baseCommentsQuery();
        $this->querySelect($query);
        $this->querySite($query);
        $this->queryPublished($query);
        $this->queryPastFuture($query);
        $this->queryConditions($query);
        $this->queryOrderBys($query);
        $this->queryScopes($query);

        $queryPayload = $this->runHooksWith('comments-query-building', [
            'query' => $query,
            'params' => $this->params->all(),
            'tag_context' => $this->context->all(),
        ]);

        $hookQuery = $queryPayload instanceof Payload ? $queryPayload->query : null;

        if ($hookQuery instanceof CommentQueryBuilder) {
            $query = $hookQuery;
        }

        $paginateValue = $this->params->int('paginate');
        $paginate = is_int($paginateValue) ? $paginateValue : 0;

        if ($paginate > 0) {
            $paginator = $query->paginate($paginate);

            $resultsPayload = $this->runHooksWith('comments-query-results', [
                'comments' => $paginator->items(),
                'query' => $query,
                'params' => $this->params->all(),
            ]);

            $hookComments = $resultsPayload instanceof Payload ? $resultsPayload->comments : null;
            $comments = (new ThreadBuilder)->build($this->hookComments($hookComments));

            return [
                'comments' => $comments->map(fn (CommentNode $node) => $node->toArray())->all(),
                'paginate' => $this->paginationData($paginator),
                'total_results' => $paginator->total(),
                'no_results' => $paginator->total() === 0,
            ];
        }

        $results = $query->get()->all();

        $resultsPayload = $this->runHooksWith('comments-query-results', [
            'comments' => $results,
            'query' => $query,
            'params' => $this->params->all(),
        ]);

        $hookComments = $resultsPayload instanceof Payload ? $resultsPayload->comments : null;
        $comments = (new ThreadBuilder)->build($this->hookComments($hookComments));

        return $this->output($comments->map(fn (CommentNode $node) => $node->toArray()));
    }

    /** @return array<string, mixed> */
    protected function paginationData(LengthAwarePaginator $paginator): array
    {
        return [
            'total_items' => $paginator->total(),
            'items_per_page' => $paginator->perPage(),
            'total_pages' => $paginator->lastPage(),
            'current_page' => $paginator->currentPage(),
            'prev_page' => $paginator->previousPageUrl(),
            'next_page' => $paginator->nextPageUrl(),
            'auto_links' => $paginator->render(),
            'links' => $paginator->linkCollection()->toArray(),
        ];
    }

    protected function baseCommentsQuery(): CommentQueryBuilder
    {
        $selection = $this->getThreadSelection();
        $hidden = $this->resolveHiddenSubtreeIds();

        $query = Comments::query();
        $query->whereNull('parent_id');
        $query->with(['allChildren' => $this->childrenEagerConstraint($hidden)]);

        if ($selection !== null) {
            $query->whereIn('thread_id', $selection);
        }

        if (! empty($hidden)) {
            $query->whereNotIn('comments.id', $hidden);
        }

        if ($this->resolveIncludeTrashed()) {
            $query->withTrashed();
        }

        return $query;
    }

    protected function resolveIncludeTrashed(): bool
    {
        if (! $this->canViewModerationForCurrentThread()) {
            return false;
        }

        return $this->params->bool('include_trashed', false) === true;
    }

    /**
     * @return list<int>
     */
    protected function resolveHiddenSubtreeIds(): array
    {
        [$includeTombstones, $includeReplies] = $this->resolveTombstoneInclusion();

        return Comments::hiddenSubtreeIdsForThreads(
            $this->getThreadSelection(),
            $includeTombstones,
            $includeReplies
        );
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    protected function resolveTombstoneInclusion(): array
    {
        if (! $this->canViewModerationForCurrentThread()) {
            return [false, false];
        }

        return [
            $this->params->bool('include_tombstones', false) === true,
            $this->params->bool('include_tombstone_replies', false) === true,
        ];
    }

    /**
     * @param  list<int>  $hidden
     */
    protected function childrenEagerConstraint(array $hidden = []): \Closure
    {
        $includeUnpublished = $this->shouldIncludeUnpublished();
        $includeSpam = $this->shouldIncludeSpam();
        $includeTrashed = $this->resolveIncludeTrashed();

        $constraint = function (mixed $query) use (&$constraint, $includeUnpublished, $includeSpam, $includeTrashed, $hidden): void {
            if (! $query instanceof EloquentBuilder) {
                return;
            }

            if (! $includeUnpublished) {
                $query->where('is_published', true);
            }

            if (! $includeSpam) {
                $query->where('is_spam', false);
            }

            if ($includeTrashed) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            if ($hidden !== []) {
                $query->whereNotIn('comments.id', $hidden);
            }

            $query->with(['allChildren' => $constraint]);
        };

        return $constraint;
    }

    protected function querySelect(CommentQueryBuilder $query): static
    {
        if ($keys = $this->getQuerySelectKeys(new Comment)) {
            $query->select($keys);
        }

        return $this;
    }

    protected function querySite(CommentQueryBuilder $query): static
    {
        $currentSite = Site::current();
        $defaultSite = $currentSite instanceof \Statamic\Sites\Site
            ? $currentSite->handle()
            : null;
        $site = $this->params->get('site') ?? $this->params->get('locale', $defaultSite);

        if ($site === '*' || ! Site::hasMultiple()) {
            return $this;
        }

        if (is_string($site) || is_int($site)) {
            $query->where('site', (string) $site);
        }

        return $this;
    }

    protected function queryPublished(CommentQueryBuilder $query): static
    {
        if (! $this->shouldIncludeUnpublished()) {
            $query->where('is_published', true);
        }

        if (! $this->shouldIncludeSpam()) {
            $query->where('is_spam', false);
        }

        return $this;
    }

    protected function shouldIncludeUnpublished(): bool
    {
        if (! $this->canViewModerationForCurrentThread()) {
            return false;
        }

        return $this->params->bool('include_unpublished', false)
            || $this->isQueryingCondition('is_published');
    }

    protected function shouldIncludeSpam(): bool
    {
        if (! $this->canViewModerationForCurrentThread()) {
            return false;
        }

        return $this->params->bool('include_unpublished', false)
            || $this->isQueryingCondition('is_spam');
    }

    protected function canViewModerationForCurrentThread(): bool
    {
        $selection = $this->getThreadSelection();
        $visibility = app(CommentVisibility::class);

        if ($selection === null) {
            return $visibility->canViewModerationEverywhere();
        }

        if ($selection === []) {
            return false;
        }

        foreach ($selection as $threadId) {
            if (! $visibility->canViewModerationForThread($threadId)) {
                return false;
            }
        }

        return true;
    }

    protected function queryPastFuture(CommentQueryBuilder $query): static
    {
        $showFuture = $this->params->bool('show_future', true);
        $showPast = $this->params->bool('show_past', true);

        if ($showFuture && $showPast) {
            return $this;
        }

        if ($showFuture) {
            $query->where('comments.created_at', '>', Carbon::now());
        } elseif ($showPast) {
            $query->where('comments.created_at', '<', Carbon::now());
        }

        return $this;
    }

    public function comments(): mixed
    {
        return $this->getComments();
    }

    /** @return list<Comment> */
    private function hookComments(mixed $comments): array
    {
        if (! is_iterable($comments)) {
            return [];
        }

        $result = [];

        foreach ($comments as $comment) {
            if ($comment instanceof Comment) {
                $result[] = $comment;
            }
        }

        return $result;
    }
}
