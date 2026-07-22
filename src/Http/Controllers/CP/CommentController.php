<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Controllers\CP;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Statamic\CP\Columns;
use Statamic\Facades\Action;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Hooks\Payload;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\Support\Traits\Hookable;
use Stillat\Meerkat\Concerns\CleansCommentData;
use Stillat\Meerkat\Concerns\ExtractsFields;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Concerns\GetsMeerkatPermissions;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\CommentQueryBuilder;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Exporters\CsvExporter;
use Stillat\Meerkat\Exporters\JsonExporter;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Hooks\CommentsIndexQuery;
use Stillat\Meerkat\Http\Resources\Comments\CommentCollection;
use Stillat\Meerkat\Http\Resources\Comments\CommentResource;
use Stillat\Meerkat\Http\ValuesResponse;
use Stillat\Meerkat\Rules\ReplyDepthLimit;
use Stillat\Meerkat\Services\ModerationAuditManager;
use Stillat\Meerkat\Services\ThreadResolver;
use Stillat\Meerkat\Support\CommentMarkdownRenderer;
use Stillat\Meerkat\Support\CommentVisibility;
use Stillat\Meerkat\Support\CpErrorResponse;
use Stillat\Meerkat\Support\Features;
use Stillat\Meerkat\Support\Identifiers;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CommentController extends CpController
{
    use CleansCommentData,
        ExtractsFields,
        GetsMeerkatConfig,
        GetsMeerkatPermissions,
        Hookable,
        QueriesFilters;

    private function commentQueryForCurrentUser(): CommentQueryBuilder
    {
        $query = Comments::query();

        app(CommentVisibility::class)->applyAccessibleScope($query, $this->getPermissions());

        return $query;
    }

    public function filter(FilteredRequest $request): CommentCollection
    {
        $this->requirePermission('view comments');

        $sortField = $this->getSortField();
        $sortDirection = $this->getSortDirection();

        $query = $this->commentQueryForCurrentUser();

        if ($sortField) {
            $query->orderBy($sortField, $sortDirection);
        }

        $filters = is_array($request->filters) ? $request->filters : [];
        $activeFilterBadges = $this->queryFilters($query, $filters, [
            'blueprints' => [$this->getBlueprint()->handle()],
        ]);

        $search = request('search');

        if (is_string($search) && $search !== '') {
            $query->leftJoin('threads', 'threads.thread_id', '=', 'comments.thread_id')
                ->where(function ($q) use ($search) {
                    $searchTerm = '%'.$search.'%';

                    $q->where('comments.comment_text', 'like', $searchTerm)
                        ->orWhere('threads.cached_title', 'like', $searchTerm);
                });
        }

        $comments = (new CommentsIndexQuery($query))->paginate($this->resolveCpPerPage());

        return (new CommentCollection($comments))
            ->blueprint($this->getBlueprint())
            ->columnPreferenceKey('meerkat.comments.columns')
            ->additional(['meta' => [
                'activeFilterBadges' => $activeFilterBadges,
            ]]);
    }

    public function checkOutstandingForSpam(): void
    {
        $this->requirePermission('check comment spam');

        /** @var CommentRepository $comments */
        $comments = app(CommentRepository::class);

        $comments->checkOutstandingForSpam();
    }

    public function exportComments(FilteredRequest $request): BinaryFileResponse
    {
        $this->requirePermission('view comments');

        $query = $this->commentQueryForCurrentUser();

        $filters = is_array($request->filters) ? $request->filters : [];
        $this->queryFilters($query, $filters, [
            'blueprints' => [$this->getBlueprint()->handle()],
        ]);

        $search = request('search');

        if (is_string($search) && $search !== '') {
            $query->leftJoin('threads', 'threads.thread_id', '=', 'comments.thread_id')
                ->where(function ($q) use ($search) {
                    $term = '%'.$search.'%';
                    $q->where('comments.comment_text', 'like', $term)
                        ->orWhere('threads.cached_title', 'like', $term);
                });
        }

        $isJson = request('format') === 'json';

        $exporter = $isJson ? new JsonExporter : new CsvExporter;

        $exporter->setConfig([])
            ->setComments($query->orderBy('comments.id')->lazy());

        return $exporter->download();
    }

    /** @return array<string, mixed> */
    protected function getCommentData(Comment $comment): array
    {
        return array_merge($comment->toDataArray(), [
            'author_id' => $comment->author_id,
            'thread_id' => $comment->thread_id,
            'created_at' => $comment->created_at?->format('Y-m-d H:i'),
            'site' => $comment->site,
            'collection' => $comment->collection,
            'moderation_status' => $comment->moderation_status,
            'moderation_reason' => $comment->moderation_reason,
            'moderation_notes' => $comment->moderation_notes,
        ]);
    }

    /** @return array{Comment, Comment} */
    private function getReplyComment(int $parent): array
    {
        /** @var CommentRepository $comments */
        $comments = app(CommentRepository::class);
        $parent = Comment::query()->findOrFail($parent);
        abort_if($parent->is_removed, 422, __('meerkat::validation.parent_visible'));
        $reply = $comments->inReplyTo($parent);
        $authUser = User::current();
        abort_if($authUser === null, 403);
        $userDetails = $this->authorDetailsFromUser($authUser);
        $reply->name = $userDetails['name'] ?? '';
        $reply->email = $userDetails['email'] ?? '';
        $reply->author_id = $this->nullableScalarString($authUser->id());
        $reply->created_at = Carbon::now();
        $reply->checked_for_spam = $reply->is_spam = $reply->is_ham = false;
        $reply->moderation_status = 'approved';

        return [$reply, $parent];
    }

    public function submitReply(int $parent): JsonResponse|CommentResource
    {
        $this->requirePermission('submit comments');
        [$reply, $parentComment] = $this->getReplyComment($parent);

        $this->assertCanAccessComment($reply);

        $entry = app(ThreadResolver::class)->resolveEntry($parentComment->thread_id)
            ?? Entry::find($parentComment->thread_id);

        if ($entry && app(CommentRepository::class)->isExplicitlyDisabledForEntry($entry)) {
            return response()->json([
                'message' => __('meerkat::validation.comments_closed'),
                'errors' => ['comment' => [__('meerkat::validation.comments_closed')]],
            ], 422);
        }

        validator(
            ['parent' => $parent],
            ['parent' => [new ReplyDepthLimit($parentComment)]],
        )->validate();

        $data = array_merge(
            $this->getCommentData($reply),
            $this->stringKeyedArray(request()->all()),
        );

        $data = $this->normalizeReplyDataForValidation($data);

        $fields = $this->getBlueprint()
            ->fields()
            ->addValues($data);

        $fields->validate();

        $values = $fields->process()->values()->all();
        $reply->comment_data = $this->cleanCommentData($values);
        $reply->comment_text = $this->extractComment($values)['comment'];

        $payload = $this->runHooksWith('before-saving-reply', [
            'comment' => $reply,
            'parent_comment' => $parentComment,
            'entry' => $entry,
            'values' => $values,
            'authenticated_user' => User::current(),
        ]);

        $hookedReply = $payload instanceof Payload ? $payload->comment : null;

        if ($hookedReply instanceof Comment) {
            $reply = $hookedReply;
        }

        if (! $reply->save()) {
            return response()->json(['saved' => false], 422);
        }

        $authUser = User::current();

        if ($authUser !== null) {
            $this->ensureAuthorMetaDataExists($authUser);
        }

        $reply->materializePath($parentComment);

        // Persist the path directly: the id is only known after the insert, and
        // the mirror file location does not depend on these columns.
        Comment::query()->whereKey($reply->id)->update([
            'path' => $reply->path,
            'visual_path' => $reply->visual_path,
        ]);

        return (new CommentResource($reply->fresh()))
            ->blueprint($this->getBlueprint())
            ->columns($this->resourceColumns());
    }

    public function getReplyData(int $parent): ValuesResponse
    {
        $this->requirePermission('submit comments');
        [$replyComment] = $this->getReplyComment($parent);

        $this->assertCanAccessComment($replyComment);

        return new ValuesResponse(
            $this->getBlueprint(),
            $this->getCommentData($replyComment),
        );
    }

    public function getCommentValues(int $id): ValuesResponse
    {
        $this->requirePermission('view comments');

        /** @var Comment $comment */
        $comment = Comment::findOrFail($id);
        $this->assertCanAccessComment($comment);

        return new ValuesResponse(
            $this->getBlueprint(),
            $this->getCommentData($comment),
        );
    }

    public function getCommentHistory(int $id): JsonResponse
    {
        $this->requirePermission('view comments');

        /** @var Comment $comment */
        $comment = Comment::findOrFail($id);
        $this->assertCanAccessComment($comment);

        try {
            $history = CommentModerationAudit::query()
                ->where('comment_id', $comment->id)
                ->latest()
                ->get()
                ->toArray();
        } catch (QueryException $e) {
            return CpErrorResponse::fromMissingTable($e);
        }

        return response()->json(['history' => $history]);
    }

    public function getCommentRevisions(int $id): JsonResponse
    {
        abort_unless(Features::revisions(), 403, __('Statamic Pro is required.'));
        $this->requirePermission('view comments');

        /** @var Comment $comment */
        $comment = Comment::findOrFail($id);
        $this->assertCanAccessComment($comment);

        try {
            /** @var Collection<int, CommentRevision> $revisions */
            $revisions = CommentRevision::query()
                ->where('comment_id', $comment->id)
                ->orderByDesc('revision_number')
                ->get();
        } catch (QueryException $e) {
            return CpErrorResponse::fromMissingTable($e);
        }

        $editorIds = $revisions->pluck('edited_by')->filter()->unique()->values();
        $editors = [];

        foreach ($editorIds as $editorId) {
            if (! is_string($editorId) && ! is_int($editorId)) {
                continue;
            }

            $editorId = (string) $editorId;
            $user = User::find($editorId);

            $editors[$editorId] = $user ? $this->userDisplay($user) : null;
        }

        $authorFallback = $this->revisionUserFromCommentAuthor($comment);

        $payload = [];

        foreach ($revisions->values() as $index => $revision) {
            $row = $revision->toArray();
            $resolved = $revision->edited_by ? ($editors[$revision->edited_by] ?? null) : null;
            $row['user'] = $resolved ?? $authorFallback;
            $row['current'] = $index === 0;
            $row['date'] = $revision->edited_at->timestamp;

            $payload[] = $row;
        }

        return response()->json(['revisions' => $payload]);
    }

    public function threadComments(string $threadId): JsonResponse
    {
        $this->requirePermission('view comments');

        abort_unless(app(CommentVisibility::class)->canViewModerationForThread($threadId), 403);

        $query = Comment::query()
            ->where('comments.thread_id', $threadId)
            ->with('userMeta')
            ->orderBy('comments.visual_path');

        app(CommentVisibility::class)->applyAccessibleScope($query, $this->getPermissions());

        /** @var Collection<int, Comment> $comments */
        $comments = $query->get();

        $renderer = app(CommentMarkdownRenderer::class);
        $namesById = $comments->keyBy('id')->map->resolvedName();

        $thread = Thread::query()->where('thread_id', $threadId)->first();
        $entry = $thread !== null ? Entry::find($thread->entry_id ?? $threadId) : Entry::find($threadId);

        return response()->json([
            'thread' => [
                'id' => $threadId,
                'title' => $thread?->cached_title ?: $entry?->get('title'),
                'url' => $entry?->absoluteUrl(),
            ],
            'comments' => $comments->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'parent_id' => $comment->parent_id,
                'depth' => (int) $comment->depth,
                'moderation_status' => $comment->moderation_status,
                'is_removed' => (bool) $comment->is_removed,
                'author' => [
                    'id' => $comment->author_id,
                    'name' => $comment->resolvedName(),
                    'email' => $comment->resolvedEmail(),
                    'initials' => Identifiers::initials($comment->resolvedName()),
                    'is_guest' => $comment->author_id === null,
                ],
                'comment_html' => $renderer->render($comment->comment_text),
                'created_at' => $comment->created_at?->toIso8601String(),
                'parent_author' => $comment->parent_id !== null ? ($namesById[$comment->parent_id] ?? null) : null,
                'actions' => Action::for($comment),
            ])->values()->all(),
        ]);
    }

    public function restoreCommentRevision(int $id, int $revisionNumber): JsonResponse
    {
        abort_unless(Features::revisions(), 403, __('Statamic Pro is required.'));
        $this->requirePermission('edit comments');

        /** @var Comment $comment */
        $comment = Comment::findOrFail($id);
        $this->assertCanAccessComment($comment);

        abort_unless(
            app(CommentRepository::class)->restoreRevision($id, $revisionNumber),
            422,
            __('meerkat::errors.revision_restore_failed'),
        );

        return response()->json(['restored' => true]);
    }

    public function updateComment(int $id): void
    {
        $this->requirePermission('edit comments');

        /** @var Comment $comment */
        $comment = Comment::findOrFail($id);

        $this->assertCanAccessComment($comment);

        $data = array_merge(
            $this->getCommentData($comment),
            $this->stringKeyedArray(request()->all()),
        );

        $fields = $this->getBlueprint()
            ->fields()
            ->addValues($data);

        $fields->validate();

        $values = $fields->process()->values()->all();

        $comment->is_published = (bool) ($values['is_published'] ?? $comment->is_published);

        $commentData = array_merge($comment->comment_data, $this->cleanCommentData($values));
        $comment->comment_data = $commentData;
        $comment->comment_text = $this->extractComment($commentData)['comment'];
        $comment->created_at = $this->carbonValue($values['created_at'] ?? null) ?? $comment->created_at;

        if ($comment->author_id === null) {
            $comment->author_email = $this->nullableScalarString($values['email'] ?? null);
            $comment->author_name = $this->nullableScalarString($values['name'] ?? null);
        }

        $comment->moderation_status = $this->nullableScalarString($values['moderation_status'] ?? null)
            ?? $comment->moderation_status;
        $comment->moderation_reason = $this->nullableScalarString($values['moderation_reason'] ?? null);
        $comment->moderation_notes = $this->nullableScalarString($values['moderation_notes'] ?? null);

        if ($comment->moderation_status === 'approved') {
            $comment->is_published = true;
            $comment->is_spam = false;
        } elseif (in_array($comment->moderation_status, ['pending', 'rejected', 'spam'])) {
            $comment->is_published = false;
        }

        if ($comment->moderation_status === 'spam') {
            $comment->is_spam = true;
            $comment->is_ham = false;
            $comment->checked_for_spam = true;
        }

        if ($comment->moderation_status === 'rejected') {
            $comment->is_spam = false;
            $comment->is_ham = false;
        }

        $comment->stampModeration($comment->moderation_status);

        $payload = $this->runHooksWith('before-updating-comment', [
            'comment' => $comment,
            'values' => $values,
            'authenticated_user' => User::current(),
        ]);

        $hookedComment = $payload instanceof Payload ? $payload->comment : null;

        if ($hookedComment instanceof Comment) {
            $comment = $hookedComment;
        }
        $moderationFields = ['moderation_status', 'moderation_reason', 'moderation_notes', 'is_published', 'is_spam', 'is_ham'];
        $diff = $this->captureModerationDiff($comment, $moderationFields);

        if (! $comment->save()) {
            abort(422, __('meerkat::errors.comment_save_failed'));
        }

        if ($diff !== []) {
            $this->recordCpModerationAudit($comment, $diff);
        }
    }

    /**
     * @param  list<string>  $moderationFields
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function captureModerationDiff(Comment $comment, array $moderationFields): array
    {
        $diff = [];

        foreach ($moderationFields as $field) {
            if ($comment->isDirty($field)) {
                $diff[$field] = [
                    'from' => $comment->getOriginal($field),
                    'to' => $comment->getAttribute($field),
                ];
            }
        }

        return $diff;
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $diff
     */
    private function recordCpModerationAudit(Comment $comment, array $diff): void
    {
        $action = array_key_exists('moderation_status', $diff)
            ? $this->cpModerationActionForStatus(
                is_string($diff['moderation_status']['to']) ? $diff['moderation_status']['to'] : null
            )
            : 'updated';

        $actorId = auth()->id();
        app(ModerationAuditManager::class)->log(
            $comment,
            $action,
            $diff,
            is_string($actorId) || is_int($actorId) ? (string) $actorId : null,
        );
    }

    private function cpModerationActionForStatus(?string $status): string
    {
        return match ($status) {
            'approved' => 'published',
            'pending' => 'unpublished',
            'rejected' => 'rejected',
            'spam' => 'marked_spam',
            default => 'updated',
        };
    }

    /**
     * @return array{id: ?string, email: string, name: string, avatar: ?string, initials: string}|null
     */
    private function revisionUserFromCommentAuthor(Comment $comment): ?array
    {

        if ($comment->author_id) {
            $user = User::find($comment->author_id);
            if ($user) {
                return $this->userDisplay($user);
            }
        }

        $name = $comment->resolvedName();
        $email = $comment->resolvedEmail();

        if (! $name && ! $email) {
            return null;
        }

        return [
            'id' => null,
            'email' => $email ?: '',
            'name' => $name ?: $email,
            'avatar' => null,
            'initials' => Identifiers::initials($name ?: $email),
        ];
    }

    /**
     * @return array{id: ?string, email: string, name: string, avatar: ?string, initials: string}
     */
    private function userDisplay(\Statamic\Contracts\Auth\User $user): array
    {
        $id = $this->nullableScalarString($user->id());
        $email = $this->nullableScalarString($user->email()) ?? '';
        $name = $this->nullableScalarString($user->name()) ?? $email;
        $avatar = $this->nullableScalarString($user->avatar());
        $initials = $this->nullableScalarString($user->initials()) ?? Identifiers::initials($name);

        return [
            'id' => $id,
            'email' => $email,
            'name' => $name,
            'avatar' => $avatar,
            'initials' => $initials,
        ];
    }

    private function getSortField(): ?string
    {
        $field = request('sort', 'created_at');

        if (! is_string($field)) {
            return null;
        }

        return [
            'id' => 'comments.id',
            'thread_id' => 'comments.thread_id',
            'author_id' => 'comments.author_id',
            'author_name' => 'comments.author_name',
            'author_email' => 'comments.author_email',
            'site' => 'comments.site',
            'collection' => 'comments.collection',
            'is_published' => 'comments.is_published',
            'checked_for_spam' => 'comments.checked_for_spam',
            'is_spam' => 'comments.is_spam',
            'is_ham' => 'comments.is_ham',
            'is_removed' => 'comments.is_removed',
            'depth' => 'comments.depth',
            'parent_id' => 'comments.parent_id',
            'replies_count' => 'comments.replies_count',
            'comment_text' => 'comments.comment_text',
            'moderation_status' => 'comments.moderation_status',
            'moderation_reason' => 'comments.moderation_reason',
            'moderated_at' => 'comments.moderated_at',
            'last_activity_at' => 'comments.last_activity_at',
            'published_at' => 'comments.published_at',
            'created_at' => 'comments.created_at',
            'updated_at' => 'comments.updated_at',
        ][$field] ?? null;
    }

    private function getSortDirection(): string
    {
        $order = request('order', 'desc');

        return is_string($order) && strtolower($order) === 'asc' ? 'asc' : 'desc';
    }

    private function resolveCpPerPage(): int
    {
        $default = max(1, $this->integerConfigValue('meerkat.cp.per_page', 50));
        $max = max(1, $this->integerConfigValue('meerkat.cp.max_per_page', 100));
        $requested = request()->integer('perPage', $default);

        if ($requested <= 0) {
            return min($default, $max);
        }

        return min($requested, $max);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeReplyDataForValidation(array $data): array
    {
        $data['created_at'] = $this->normalizeCreatedAtForValidation($data['created_at'] ?? null);

        foreach (['thread_id', 'collection', 'site', 'author_id'] as $relationField) {
            if (! array_key_exists($relationField, $data)) {
                continue;
            }

            $value = $data[$relationField];

            if ($value === null || $value === '') {
                $data[$relationField] = [];

                continue;
            }

            if (is_array($value)) {
                continue;
            }

            $data[$relationField] = [$value];
        }

        return $data;
    }

    private function normalizeCreatedAtForValidation(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return now()->format('Y-m-d\TH:i:s.v\Z');
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d\TH:i:s.v\Z');
        }

        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $value)) {
            return $value;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function nullableScalarString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) || is_bool($value) ? (string) $value : null;
    }

    private function integerConfigValue(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function carbonValue(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resourceColumns(): Columns
    {
        $columns = $this->getBlueprint()->columns();
        $columns->setPreferred('meerkat.comments.columns');

        return $columns->rejectUnlisted();
    }

    private function requirePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user !== null && $user->can($permission), 403);
    }
}
