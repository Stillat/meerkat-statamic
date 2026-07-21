<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database;

use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Database\Models\Comment;

/** @extends BaseQueryBuilder<Comment> */
class CommentQueryBuilder extends BaseQueryBuilder
{
    const TABLE = 'comments';

    public const COLUMNS = [
        'id', 'thread_id', 'timestamp_id', 'author_id',
        'site', 'collection', 'is_published',
        'checked_for_spam', 'is_spam', 'is_ham',
        'is_removed', 'removed_at', 'removed_by', 'removed_reason',
        'moderation_status', 'moderation_reason', 'moderation_notes',
        'moderated_at', 'moderated_by', 'last_activity_at', 'published_at',
        'author_name', 'author_email',
        'user_ip', 'user_agent', 'referer',
        'depth', 'path', 'visual_path',
        'parent_id', 'replies_count', 'comment_data', 'comment_text',
        'created_at', 'updated_at', 'deleted_at',

        'name', 'email',
    ];

    private const DYNAMIC_COLUMNS = [
        'name', 'email',
    ];

    /**
     * @param  array<int, Model>  $models
     * @return EloquentCollection<int, Comment>
     */
    protected function modelCollection(array $models): EloquentCollection
    {
        $comments = [];

        foreach ($models as $model) {
            if (! $model instanceof Comment) {
                throw new InvalidArgumentException('Comment queries can only contain Comment models.');
            }

            $comments[] = $model;
        }

        return new EloquentCollection($comments);
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {

        if (is_string($column) && in_array($column, self::DYNAMIC_COLUMNS, true)) {
            $model = $this->builder->getModel();
            $prefix = $model->getConnection()->getTablePrefix();
            $comments = $prefix.'comments';
            $usersMeta = $prefix.'users_meta';
            $defaultValueValue = $column === 'name'
                ? Settings::get('authors.anonymous_author', 'Anonymous User')
                : Settings::get('authors.anonymous_email', 'no-email@example.org');
            $defaultValue = is_string($defaultValueValue)
                ? $defaultValueValue
                : ($column === 'name' ? 'Anonymous User' : 'no-email@example.org');

            [$value, $operator] = $this->prepareValueAndOperator(
                $value,
                $operator,
                func_num_args() === 2
            );

            $operator = $this->nullableString($operator, 'operator');

            if ($operator === null) {
                throw new InvalidArgumentException('Dynamic field queries require an operator.');
            }

            $operator = strtolower(trim($operator));

            if (! array_key_exists($operator, $this->operators)) {
                throw new InvalidArgumentException("Unsupported operator [{$operator}] for the dynamic [{$column}] column.");
            }

            $coalesceExpression = "COALESCE({$comments}.author_{$column}, {$usersMeta}.{$column}, ?)";

            if ($operator === 'like' || $operator === 'not like') {
                $this->builder->whereRaw(
                    "LOWER({$coalesceExpression}) {$operator} ?",
                    [$defaultValue, $this->lowercaseValue($value)],
                    $boolean
                );

                return $this;
            }

            $this->builder->whereRaw(
                "{$coalesceExpression} {$operator} ?",
                [$defaultValue, $value],
                $boolean
            );

            return $this;
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    protected function column(mixed $column): string|Expression
    {
        if (! is_string($column)) {
            return parent::column($column);
        }

        if (Str::contains($column, '.')) {
            return $column;
        }

        if (in_array($column, self::DYNAMIC_COLUMNS, true)) {
            return $column;
        }

        if (! in_array($column, self::COLUMNS, true) && ! Str::startsWith($column, 'comment_data->')) {
            $column = 'comment_data->'.$column;
        }

        return self::TABLE.'.'.$column;
    }

    /**
     * Include soft-deleted comments in the results.
     */
    public function withTrashed(bool $withTrashed = true): self
    {
        $this->builder->withTrashed($withTrashed);

        return $this;
    }

    public function withoutTrashed(): self
    {
        $this->builder->withoutTrashed();

        return $this;
    }

    public function onlyTrashed(): self
    {
        $this->builder->onlyTrashed();

        return $this;
    }

    public function recent(int $days = 7): self
    {
        return $this->where('comments.created_at', '>=', now()->subDays($days));
    }

    public function since(DateTimeInterface|string $date): self
    {
        return $this->where('comments.created_at', '>=', $date);
    }

    public function between(DateTimeInterface|string $start, DateTimeInterface|string $end): self
    {
        return $this->whereBetween('comments.created_at', [$start, $end]);
    }

    public function today(): self
    {
        return $this->whereDate('comments.created_at', today());
    }

    public function roots(): self
    {
        return $this->whereNull('comments.parent_id');
    }

    public function leaves(): self
    {
        return $this->whereNotIn('comments.id', function (QueryBuilder $query): void {
            $query->select('parent_id')
                ->from('comments')
                ->whereNotNull('parent_id');
        });
    }

    public function atDepth(int $depth): self
    {
        return $this->where('comments.depth', $depth);
    }

    public function depthBetween(int $min, int $max): self
    {
        return $this->whereBetween('comments.depth', [$min, $max]);
    }

    public function descendantsOf(int $commentId): self
    {
        $comment = $this->builder->getModel()->find($commentId);

        if (! $comment) {
            return $this->whereRaw('1 = 0');
        }

        return $this->where('path', 'like', $this->commentPath($comment).'.%');
    }

    public function subtreeOf(int $commentId): self
    {
        $comment = $this->builder->getModel()->find($commentId);

        if (! $comment) {
            return $this->whereRaw('1 = 0');
        }

        return $this->where(function (CommentQueryBuilder $query) use ($comment): void {
            $query->where('comments.id', $comment->getKey())
                ->orWhere('path', 'like', $this->commentPath($comment).'.%');
        });
    }

    public function withReplies(): self
    {
        return $this->where('comments.replies_count', '>', 0);
    }

    public function withoutReplies(): self
    {
        return $this->where('comments.replies_count', 0);
    }

    public function published(): self
    {
        return $this->where('is_published', true);
    }

    public function unpublished(): self
    {
        return $this->where('is_published', false);
    }

    public function spam(): self
    {
        return $this->where('is_spam', true);
    }

    public function ham(): self
    {
        return $this->where('is_ham', true);
    }

    public function pendingModeration(): self
    {
        return $this->where('moderation_status', 'pending');
    }

    public function approved(): self
    {
        return $this->where('moderation_status', 'approved');
    }

    public function rejected(): self
    {
        return $this->where('moderation_status', 'rejected');
    }

    public function byAuthor(string|int $identifier): self
    {
        if (is_numeric($identifier)) {
            return $this->where('comments.author_id', $identifier);
        }

        $this->builder->where(function (EloquentBuilder $query) use ($identifier): void {
            $query->where('comments.author_email', $identifier)
                ->orWhereIn('comments.author_id', function (QueryBuilder $sub) use ($identifier): void {
                    $sub->select('user_id')->from('users_meta')->where('email', $identifier);
                });
        });

        return $this;
    }

    public function authenticated(): self
    {
        return $this->whereNotNull('author_id');
    }

    public function guests(): self
    {
        return $this->whereNull('author_id');
    }

    /** @param list<string|int> $identifiers */
    public function byAuthors(array $identifiers): self
    {
        $this->builder->where(function (EloquentBuilder $query) use ($identifiers): void {
            $ids = array_filter($identifiers, is_numeric(...));
            $emails = array_filter($identifiers, fn ($id) => ! is_numeric($id));

            if ($ids !== []) {
                $query->whereIn('comments.author_id', $ids);
            }

            if ($emails !== []) {
                $query->orWhere(function (EloquentBuilder $q) use ($emails): void {
                    $q->whereIn('comments.author_email', $emails)
                        ->orWhereIn('comments.author_id', function (QueryBuilder $sub) use ($emails): void {
                            $sub->select('user_id')->from('users_meta')->whereIn('email', $emails);
                        });
                });
            }
        });

        return $this;
    }

    public function forThread(string $threadId): self
    {
        return $this->where('thread_id', $threadId);
    }

    /** @param list<string> $threadIds */
    public function forThreads(array $threadIds): self
    {
        return $this->whereIn('thread_id', $threadIds);
    }

    public function forSite(string $site): self
    {
        return $this->where('site', $site);
    }

    public function forCollection(string $collection): self
    {
        return $this->where('collection', $collection);
    }

    public function newest(): self
    {
        return $this->orderBy('comments.created_at', 'desc');
    }

    public function oldest(): self
    {
        return $this->orderBy('comments.created_at', 'asc');
    }

    public function hierarchical(): self
    {
        return $this->orderBy('visual_path', 'asc');
    }

    public function byDepth(string $direction = 'asc'): self
    {
        return $this->orderBy('depth', $direction);
    }

    public function search(string $term): self
    {
        return $this->where('comment_text', 'like', '%'.$term.'%');
    }

    public function searchAll(string $term): self
    {
        return $this->where(function (CommentQueryBuilder $query) use ($term): void {
            $query->where('comment_text', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%');
        });
    }

    public function hasField(string $field): self
    {
        return $this->whereNotNull($field);
    }

    /**
     * @param  mixed  $operator
     * @param  mixed  $value
     */
    public function whereField(string $field, $operator = null, $value = null): self
    {
        return $this->where($field, $operator, $value);
    }

    /** @return array<string, int> */
    public function countByThread(): array
    {
        $results = $this->builder
            ->withoutGlobalScopes()
            ->select([])
            ->selectRaw('thread_id')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('thread_id')
            ->orderByDesc('count')
            ->get();

        $counts = [];

        foreach ($results as $result) {
            $threadId = $result->getAttribute('thread_id');

            if (is_string($threadId)) {
                $counts[$threadId] = $this->aggregateCount($result->getAttribute('count'));
            }
        }

        return $counts;
    }

    /** @return array<string, int> */
    public function countByAuthor(): array
    {
        $prefix = $this->builder->getModel()->getConnection()->getTablePrefix();
        $defaultName = Settings::get('authors.anonymous_author', 'Anonymous User');

        $results = $this->builder
            ->withoutGlobalScopes()
            ->leftJoin('users_meta', 'users_meta.user_id', '=', 'comments.author_id')
            ->select([])
            ->selectRaw("COALESCE({$prefix}comments.author_name, {$prefix}users_meta.name, ?) as resolved_name", [$defaultName])
            ->selectRaw('COUNT(*) as count')
            ->groupBy('resolved_name')
            ->orderByDesc('count')
            ->get();

        $counts = [];

        foreach ($results as $result) {
            $name = $result->getAttribute('resolved_name');

            if (is_string($name)) {
                $counts[$name] = $this->aggregateCount($result->getAttribute('count'));
            }
        }

        return $counts;
    }

    /** @return array<int, int> */
    public function countByDepth(): array
    {
        $results = $this->builder
            ->withoutGlobalScopes()
            ->select([])
            ->selectRaw('depth')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('depth')
            ->orderBy('depth')
            ->get();

        $counts = [];

        foreach ($results as $result) {
            $depth = $result->getAttribute('depth');

            if (is_int($depth)) {
                $counts[$depth] = $this->aggregateCount($result->getAttribute('count'));
            } elseif (is_string($depth) && is_numeric($depth)) {
                $counts[(int) $depth] = $this->aggregateCount($result->getAttribute('count'));
            }
        }

        return $counts;
    }

    private function commentPath(Comment $comment): string
    {
        $path = $comment->getAttribute('path');

        if (! is_string($path)) {
            throw new InvalidArgumentException('Stored comment paths must be strings.');
        }

        return $path;
    }

    private function aggregateCount(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException('Aggregate counts must be integers.');
    }

    /**
     * @return list<array{thread_id: mixed, comment_count: mixed, participant_count: mixed, last_activity: mixed}>
     */
    public function mostActiveThreads(int $limit = 10): array
    {
        $prefix = $this->builder->getModel()->getConnection()->getTablePrefix();

        $results = $this->builder
            ->withoutGlobalScopes()
            ->select([])
            ->selectRaw("{$prefix}comments.thread_id as thread_id")
            ->selectRaw('COUNT(*) as comment_count')
            ->selectRaw("COUNT(DISTINCT COALESCE({$prefix}comments.author_id, {$prefix}comments.author_email)) as participant_count")
            ->selectRaw("MAX({$prefix}comments.created_at) as last_activity")
            ->groupBy('thread_id')
            ->orderByDesc('comment_count')
            ->limit($limit)
            ->get();

        return array_values($results->map(fn (Comment $row): array => [
            'thread_id' => $row->getAttribute('thread_id'),
            'comment_count' => $row->getAttribute('comment_count'),
            'participant_count' => $row->getAttribute('participant_count'),
            'last_activity' => $row->getAttribute('last_activity'),
        ])->all());
    }

    /**
     * @return array{
     *     total_comments: int,
     *     root_comments: int,
     *     total_replies: int,
     *     participants: int,
     *     max_depth: mixed,
     *     avg_depth: float,
     *     published_count: int,
     *     spam_count: int,
     *     first_comment: mixed,
     *     last_comment: mixed
     * }
     */
    public function threadStats(string $threadId): array
    {
        $comments = $this->builder
            ->withoutGlobalScopes()
            ->where('thread_id', $threadId)
            ->get();

        if ($comments->isEmpty()) {
            return [
                'total_comments' => 0,
                'root_comments' => 0,
                'total_replies' => 0,
                'participants' => 0,
                'max_depth' => 0,
                'avg_depth' => 0,
                'published_count' => 0,
                'spam_count' => 0,
                'first_comment' => null,
                'last_comment' => null,
            ];
        }

        $participants = $comments
            ->map(fn ($c) => $c->author_id ?: $c->author_email)
            ->filter()
            ->unique()
            ->count();

        return [
            'total_comments' => $comments->count(),
            'root_comments' => $comments->where('parent_id', null)->count(),
            'total_replies' => $comments->where('parent_id', '!=', null)->count(),
            'participants' => $participants,
            'max_depth' => $comments->max('depth') ?? 0,
            'avg_depth' => round($comments->avg('depth') ?? 0, 2),
            'published_count' => $comments->where('is_published', true)->count(),
            'spam_count' => $comments->where('is_spam', true)->count(),
            'first_comment' => $comments->min('created_at'),
            'last_comment' => $comments->max('created_at'),
        ];
    }

    /**
     * @return list<array{name: mixed, email: mixed, comment_count: mixed, threads_participated: mixed, first_comment: mixed, last_comment: mixed}>
     */
    public function topContributors(int $limit = 10): array
    {
        $prefix = $this->builder->getModel()->getConnection()->getTablePrefix();
        $defaultName = Settings::get('authors.anonymous_author', 'Anonymous User');
        $defaultEmail = Settings::get('authors.anonymous_email', 'no-email@example.org');

        $nameExpr = "COALESCE({$prefix}comments.author_name, {$prefix}users_meta.name, ?)";
        $emailExpr = "COALESCE({$prefix}comments.author_email, {$prefix}users_meta.email, ?)";

        $results = $this->builder
            ->withoutGlobalScopes()
            ->leftJoin('users_meta', 'users_meta.user_id', '=', 'comments.author_id')
            ->select([])
            ->selectRaw("{$nameExpr} as resolved_name", [$defaultName])
            ->selectRaw("{$emailExpr} as resolved_email", [$defaultEmail])
            ->selectRaw('COUNT(*) as comment_count')
            ->selectRaw("COUNT(DISTINCT {$prefix}comments.thread_id) as threads_participated")
            ->selectRaw("MIN({$prefix}comments.created_at) as first_comment")
            ->selectRaw("MAX({$prefix}comments.created_at) as last_comment")
            ->groupBy('resolved_name', 'resolved_email')
            ->orderByDesc('comment_count')
            ->limit($limit)
            ->get();

        return array_values($results->map(fn (Comment $row): array => [
            'name' => $row->getAttribute('resolved_name'),
            'email' => $row->getAttribute('resolved_email'),
            'comment_count' => $row->getAttribute('comment_count'),
            'threads_participated' => $row->getAttribute('threads_participated'),
            'first_comment' => $row->getAttribute('first_comment'),
            'last_comment' => $row->getAttribute('last_comment'),
        ])->all());
    }
}
