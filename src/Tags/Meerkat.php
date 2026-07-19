<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tags;

use Illuminate\Support\ViewErrorBag;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\User as UserFacade;
use Statamic\Fields\Value;
use Statamic\Tags\Tags;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Services\ThreadResolver;
use Stillat\Meerkat\Support\CommentVisibility;
use Stillat\Meerkat\Support\FrontendAssets;
use Stillat\Meerkat\Tags\Concerns\GetsComments;
use Stillat\Meerkat\Tags\Concerns\RendersForm;

class Meerkat extends Tags
{
    use GetsComments,
        RendersForm;

    protected function getThreadId(): ?string
    {

        if ($this->params->hasAny(['thread', 'from_thread'])) {
            $selection = $this->parseThreadSelection(
                $this->params->get('thread') ?? $this->params->get('from_thread')
            );

            if ($selection === null) {
                return null;
            }

            return $selection[0] ?? null;
        }

        if ($shared = $this->resolveSharedContextValue()) {
            return $this->resolveThreadReference($shared);
        }

        $value = $this->context['id'] ?? null;

        if (! $value && isset($this->context['page'])) {
            $page = $this->context['page'];

            if (is_object($page) && method_exists($page, 'id')) {
                $value = $page->id();
            }
        }

        $value = $value instanceof Value ? $value->value() : $value;

        $page = $this->context['page'] ?? null;
        if ($page instanceof Entry) {
            return app(ThreadResolver::class)->forEntry($page);
        }

        $entry = $value ? EntryFacade::find($value) : null;

        if ($entry) {
            return app(ThreadResolver::class)->forEntry($entry);
        }

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * @return list<string>|null
     */
    protected function getThreadSelection(): ?array
    {
        if ($this->params->hasAny(['thread', 'from_thread'])) {
            return $this->parseThreadSelection(
                $this->params->get('thread') ?? $this->params->get('from_thread')
            );
        }

        $single = $this->getThreadId();

        return ($single === null || $single === '') ? [] : [$single];
    }

    /**
     * @return list<string>|null Null for a wildcard; otherwise resolved thread IDs.
     */
    protected function parseThreadSelection(mixed $raw): ?array
    {
        if ($raw instanceof Value) {
            $raw = $raw->value();
        }

        if (! is_array($raw) && ! is_string($raw) && ! is_int($raw)) {
            return [];
        }

        $tokens = is_array($raw) ? $raw : explode('|', (string) $raw);

        $tokens = array_values(array_filter(
            array_map(static fn (mixed $token): string => is_string($token) || is_int($token) ? trim((string) $token) : '', $tokens),
            static fn (string $token) => $token !== ''
        ));

        if (in_array('*', $tokens, true)) {
            return null;
        }

        $ids = [];

        foreach ($tokens as $token) {
            $ids[] = $this->resolveThreadReference($token);
        }

        return array_values(array_unique($ids));
    }

    private function resolveSharedContextValue(): ?string
    {
        $shareField = config('meerkat.publishing.share_field');

        if (! $shareField || ! isset($this->context[$shareField])) {
            return null;
        }

        $raw = $this->context[$shareField];

        if ($raw instanceof Value) {
            $raw = $raw->value();
        }

        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        return is_string($raw) || is_int($raw) ? (string) $raw : null;
    }

    private function resolveThreadReference(string $value): string
    {
        return app(ThreadResolver::class)->resolveReference($value) ?? $value;
    }

    public function commentCount(): int
    {
        $threadId = $this->getThreadId();

        if (! $threadId) {
            return 0;
        }

        $siteValue = $this->params->get('site') ?? $this->params->get('locale');
        $site = is_string($siteValue) || is_int($siteValue) ? (string) $siteValue : null;

        if (($site ?? null) === '*') {
            $site = null;
        }

        $visibility = app(CommentVisibility::class);

        if ($this->params->bool('include_unpublished', false) && $visibility->canViewModerationForThread($threadId)) {
            $query = Comment::query()->where('thread_id', $threadId);

            if ($site ?? null) {
                $query->where('site', $site);
            }

            return $query->count();
        }

        return $visibility->publicCount($threadId, $site ?? null);
    }

    public function commentsEnabled(): bool
    {
        $threadId = $this->getThreadId();

        if (! $threadId) {
            return false;
        }

        $entry = app(ThreadResolver::class)->resolveEntry($threadId) ?? EntryFacade::find($threadId);

        if (! $entry) {
            return false;
        }

        return app(CommentRepository::class)->areCommentsEnabledForEntry($entry);
    }

    /**
     * @return array{has_message: bool, message: ?string, submission_created: bool}
     */
    public function success(): array
    {
        $message = session('meerkat.success');

        return [
            'has_message' => $message !== null,
            'message' => is_string($message) ? $message : null,
            'submission_created' => (bool) session('meerkat.submission_created', false),
        ];
    }

    /**
     * @return array{has_errors: bool, count: int, messages: list<string>}
     */
    public function errors(): array
    {
        $bag = session('errors');
        $rawMessages = $bag instanceof ViewErrorBag && $bag->hasBag('meerkat')
            ? $bag->getBag('meerkat')->all()
            : [];
        $messages = array_values(array_filter($rawMessages, is_string(...)));

        return [
            'has_errors' => $messages !== [],
            'count' => count($messages),
            'messages' => $messages,
        ];
    }

    public function repliesTo(): string
    {
        return '<script src="'.e(FrontendAssets::repliesUrl()).'"></script>';
    }

    /** @return array<string, mixed> */
    public function debug(): array
    {
        $shareField = config('meerkat.publishing.share_field');
        $rawShareValue = $shareField ? ($this->context[$shareField] ?? null) : null;

        if ($rawShareValue instanceof Value) {
            $rawShareValue = $rawShareValue->value();
        }

        $isSharing = $this->resolveSharedContextValue() !== null;
        $logical = $this->context['id'] ?? null;
        if ($logical instanceof Value) {
            $logical = $logical->value();
        }

        $effective = $this->getThreadId();

        $rows = [
            ['label' => 'Is sharing context', 'value' => $isSharing ? 'yes' : 'no'],
            ['label' => 'Share field', 'value' => $shareField ?: '(disabled)'],
            ['label' => 'Share field value', 'value' => $this->formatDebugValue($rawShareValue)],
            ['label' => 'Logical context (page id)', 'value' => $this->formatDebugValue($logical)],
            ['label' => 'Effective thread id', 'value' => $effective ?? ''],
            ['label' => 'Max reply depth', 'value' => $this->formatDebugValue(config('meerkat.publishing.max_reply_depth') ?? 'unlimited')],
        ];

        return [
            'is_sharing' => $isSharing,
            'share_field' => $shareField,
            'share_field_value' => $rawShareValue,
            'logical_context_id' => $logical,
            'effective_thread_id' => $effective,
            'rows' => $rows,
        ];
    }

    private function formatDebugValue(mixed $value): string
    {
        if ($value === null) {
            return '(unset)';
        }

        if (is_array($value)) {
            return '['.implode(', ', array_map($this->formatDebugValue(...), $value)).']';
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : get_debug_type($value);
    }

    /** @return list<array<string, mixed>> */
    public function recentComments(): array
    {
        $limit = $this->positiveIntegerParam('limit', 5);

        return array_values(collect(app(CommentVisibility::class)->recentPublicComments($limit))
            ->map(function (Comment $comment) {
                $row = $comment->toDataArray();
                $row['thread'] = app(ThreadResolver::class)->resolveEntry($comment->thread_id)
                    ?? EntryFacade::find($comment->thread_id);

                return $row;
            })
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public function topThreads(): array
    {
        $limit = $this->positiveIntegerParam('limit', 5);
        $resolver = app(ThreadResolver::class);

        return array_values(collect(app(CommentVisibility::class)->topPublicThreads($limit))
            ->map(function (array $row) use ($resolver) {
                $row['thread'] = $resolver->resolveEntry($row['thread_id'])
                    ?? EntryFacade::find($row['thread_id']);

                return $row;
            })
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public function authorHistory(): array
    {
        $identifier = $this->params->get('identifier');

        if (! $identifier) {
            $identifier = UserFacade::current()?->email();
        }

        if (! $identifier) {
            return [];
        }

        if (! is_string($identifier) && ! is_int($identifier)) {
            return [];
        }

        $identifier = (string) $identifier;

        $limit = $this->positiveIntegerParam('limit', 20);
        $publishedOnly = $this->params->bool('published_only', true);

        $visibility = app(CommentVisibility::class);

        if ($publishedOnly || ! $visibility->canViewModeration()) {
            return array_values(collect($visibility->publicAuthorHistory($identifier, $limit))
                ->map(fn (Comment $comment) => $comment->toDataArray())
                ->all());
        }

        $query = Comments::query()->byAuthor($identifier)->newest();
        $visibility->applyAccessibleScope($query);
        $query->limit($limit);

        $comments = [];

        foreach ($query->get() as $comment) {
            $comments[] = $comment->toDataArray();
        }

        return $comments;
    }

    /** @return array<string, mixed> */
    public function threadStats(): array
    {
        $threadId = $this->getThreadId();

        if (! $threadId) {
            return [];
        }

        $visibility = app(CommentVisibility::class);

        if ($this->params->bool('include_moderation', false) && $visibility->canViewModerationForThread($threadId)) {
            return $this->stringKeyedArray(app(ThreadMetricsManager::class)
                ->getThreadMetric($threadId)
                ->toArray());
        }

        return $visibility->publicMetricArray($threadId);
    }

    private function positiveIntegerParam(string $key, int $default): int
    {
        $value = $this->params->get($key, $default);

        if (is_int($value)) {
            return max(1, $value);
        }

        return is_string($value) && is_numeric($value) ? max(1, (int) $value) : $default;
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
