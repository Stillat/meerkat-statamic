<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Resources\Comments;

use Illuminate\Http\Request;
use Statamic\Actions\Action as StatamicAction;
use Statamic\Facades\Action;
use Statamic\Facades\Blink;
use Statamic\Hooks\Payload;
use Statamic\Support\Traits\Hookable;
use Stillat\Meerkat\Actions\CheckForSpam;
use Stillat\Meerkat\Actions\DeleteComment;
use Stillat\Meerkat\Actions\MarkAsHam;
use Stillat\Meerkat\Actions\MarkAsSpam;
use Stillat\Meerkat\Actions\Publish;
use Stillat\Meerkat\Actions\RejectComment;
use Stillat\Meerkat\Actions\RemoveCommentSubtree;
use Stillat\Meerkat\Actions\RestoreComment;
use Stillat\Meerkat\Actions\Unpublish;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Http\Resources\ListedResource;
use Stillat\Meerkat\Support\CommentMarkdownRenderer;
use Stillat\Meerkat\Support\Identifiers;

class CommentResource extends ListedResource
{
    use Hookable;

    /** @var list<class-string<StatamicAction>> */
    private const CACHEABLE_ACTIONS = [
        CheckForSpam::class,
        DeleteComment::class,
        MarkAsHam::class,
        MarkAsSpam::class,
        Publish::class,
        RejectComment::class,
        RemoveCommentSubtree::class,
        RestoreComment::class,
        Unpublish::class,
    ];

    /** @return array<string, mixed> */
    public function values(Request $request): array
    {
        /** @var Comment $comment */
        $comment = $this->resource;

        $values = array_merge($comment->toDataArray(), [
            'actions' => $this->buildActions($comment),
            'comment_text' => $comment->comment_text,
            'comment_html' => app(CommentMarkdownRenderer::class)->render($comment->comment_text),
            'has_been_checked_for_spam' => $comment->checked_for_spam,
            'thread_id' => $comment->thread_id,
            'thread' => $this->buildThreadSummary($comment),
            'author' => $this->buildAuthor($comment),
            'parent_summary' => $this->buildParentSummary($comment),
            'created_at_display' => $this->formatCreatedAt($comment),
            'created_at_iso' => $comment->created_at?->toIso8601ZuluString('millisecond'),
        ]);

        $payload = $this->runHooksWith('values', [
            'comment' => $comment,
            'values' => $values,
            'request' => $request,
        ]);

        if (! $payload instanceof Payload || ! is_array($payload->values)) {
            return $values;
        }

        return $this->stringKeyedArray($payload->values, $values);
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values, array $fallback): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                return $fallback;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function formatCreatedAt(Comment $comment): ?string
    {
        if (! $comment->created_at) {
            return null;
        }

        return $comment->created_at->isToday()
            ? $comment->created_at->format('g:i A')
            : $comment->created_at->format('M j, Y g:i A');
    }

    /** @return array{id: string|null, name: string, email: string|null, initials: string, is_guest: bool} */
    private function buildAuthor(Comment $comment): array
    {
        $name = $comment->resolvedName();
        $email = $comment->resolvedEmail();

        return [
            'id' => $comment->author_id,
            'name' => $name,
            'email' => $email,
            'initials' => Identifiers::initials($name),
            'is_guest' => $comment->author_id === null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildActions(Comment $comment): array
    {
        $payload = [];

        foreach (Action::for($comment) as $action) {
            if (! $action instanceof StatamicAction) {
                continue;
            }

            if (! in_array($action::class, self::CACHEABLE_ACTIONS, true)) {
                $payload[] = $this->stringKeyedArray($action->toArray(), []);

                continue;
            }

            $cached = Blink::once(
                'meerkat.cp.action-payload.'.$action::class,
                fn (): array => $action->toArray()
            );

            $values = is_array($cached) ? $cached : $action->toArray();
            $payload[] = $this->stringKeyedArray($values, []);
        }

        return $payload;
    }

    /** @return array{id: string, title: mixed, permalink: ?string, url: ?string}|null */
    private function buildThreadSummary(Comment $comment): ?array
    {
        $summary = Blink::once('meerkat.thread.summary.'.$comment->thread_id, function () use ($comment): ?array {
            $entry = Comments::getCommentEntry($comment);

            if ($entry === null) {
                return null;
            }

            return [
                'id' => $entry->id(),
                'title' => $entry->get('title'),
                'permalink' => $entry->absoluteUrl(),
                'url' => $entry->url(),
            ];
        });

        if (! is_array($summary)) {
            return null;
        }

        $id = $summary['id'] ?? null;
        $permalink = $summary['permalink'] ?? null;
        $url = $summary['url'] ?? null;

        if (! is_string($id)
            || (! is_string($permalink) && $permalink !== null)
            || (! is_string($url) && $url !== null)) {
            return null;
        }

        return [
            'id' => $id,
            'title' => $summary['title'] ?? null,
            'permalink' => $permalink,
            'url' => $url,
        ];
    }

    /** @return array{id: int, author_name: string, snippet: string}|null */
    private function buildParentSummary(Comment $comment): ?array
    {
        if (! $comment->parent_id) {
            return null;
        }

        $parent = $comment->parent;

        if (! $parent) {
            return null;
        }

        return [
            'id' => $parent->id,
            'author_name' => $parent->resolvedName(),
            'snippet' => $this->snippet($parent->comment_text),
        ];
    }

    protected function shouldPreProcessIndex(string $key): bool
    {
        return ! in_array($key, ['thread_id', 'author_id'], true);
    }

    private function snippet(?string $text, int $length = 80): string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length).'…';
    }
}
