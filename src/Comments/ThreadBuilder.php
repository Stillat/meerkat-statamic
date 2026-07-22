<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Comments;

use Illuminate\Support\Collection;
use Statamic\Data\BulkAugmentor;
use Statamic\Fields\Value;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Support\CommentMarkdownRenderer;
use Stillat\Meerkat\Support\CommentVisibility;

class ThreadBuilder
{
    /** @var array<string, bool> */
    private array $replyAvailabilityByThread = [];

    /** @var list<string> */
    private array $removeItems = [
        'comment_text',
        'comment_data',
        'collection',
        'site',
    ];

    /** @var array<string, string> */
    private array $remap = [
        'author_id' => 'author',
        'thread_id' => 'thread',
    ];

    /** @var list<string> */
    private array $resolveItems = [
        'depth',
        'name',
        'id',
        'is_ham',
        'is_published',
        'is_spam',
        'parent_id',
    ];

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private function remapItems(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        $data = $normalized;

        foreach ($this->remap as $oldKey => $newKey) {
            $data[$newKey] = $data[$oldKey];
            unset($data[$oldKey]);
        }

        foreach ($this->removeItems as $item) {
            unset($data[$item]);
        }

        foreach ($this->resolveItems as $item) {
            if (($value = $data[$item] ?? null) instanceof Value) {
                $data[$item] = $value->value();
            }
        }

        return PublicCommentData::guard($data);
    }

    /**
     * @param  iterable<Comment>  $comments
     * @return Collection<int, CommentNode>
     */
    public function build($comments): Collection
    {
        $augmentor = BulkAugmentor::make($comments);

        if (! $augmentor instanceof BulkAugmentor) {
            throw new \LogicException('Statamic returned an invalid bulk augmentor.');
        }

        $nodes = $augmentor->map(function (Comment $item, mixed $data): CommentNode {
            $childNodes = $this->build($this->resolveChildren($item));

            return new CommentNode(
                comment: $this->remapItems(is_array($data) ? $data : []),
                commentHtml: app(CommentMarkdownRenderer::class)->render($item->comment_text),
                gravatar: $item->gravatarUrl(),
                anchor: 'comment-'.$item->id,
                permalink: '#comment-'.$item->id,
                depth: $this->integerValue($item->depth),
                repliesCount: $this->integerValue($item->replies_count),
                hasReplies: $childNodes->isNotEmpty(),
                isReply: $item->parent_id !== null,
                isRoot: $item->parent_id === null,
                isRemoved: (bool) $item->is_removed,
                isDeleted: $item->trashed(),
                children: $childNodes,
                currentUser: $this->resolveCurrentUserContext($item),
            );
        });

        if (! $nodes instanceof Collection) {
            throw new \LogicException('Statamic returned an invalid augmented node collection.');
        }

        $nodes = $nodes->all();
        $result = [];

        foreach ($nodes as $node) {
            if ($node instanceof CommentNode) {
                $result[] = $node;
            }
        }

        return collect($result);
    }

    /**
     * @return array{is_authenticated: bool, is_author: bool, can_edit: bool, can_delete: bool, can_reply: bool, can_report_spam: bool}
     */
    private function resolveCurrentUserContext(Comment $comment): array
    {
        $user = auth()->user();
        $userId = auth()->id();

        $isAuthor = $userId !== null && (string) $userId === (string) $comment->author_id;
        $depthAllowsReply = $this->depthAllowsReplyTo($comment);

        if ($user === null) {

            $acceptsGuests = Settings::get('publishing.only_accept_comments_from_authenticated_users', false) === false;

            return [
                'is_authenticated' => false,
                'is_author' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_reply' => $acceptsGuests && $depthAllowsReply && $this->threadAcceptsReplies($comment),
                'can_report_spam' => false,
            ];
        }

        $canModerateThisComment = app(CommentVisibility::class)->canViewModerationForComment($comment);

        return [
            'is_authenticated' => true,
            'is_author' => $isAuthor,
            'can_edit' => $canModerateThisComment && (bool) $user->can('edit comments'),
            'can_delete' => $canModerateThisComment && (bool) $user->can('delete comments'),
            'can_reply' => $user->can('submit comments') && $depthAllowsReply && $this->threadAcceptsReplies($comment),
            'can_report_spam' => $canModerateThisComment && (bool) $user->can('report comment spam'),
        ];
    }

    private function depthAllowsReplyTo(Comment $comment): bool
    {
        $max = config('meerkat.publishing.max_reply_depth');

        $max = $this->integerValue($max);

        if ($max <= 0) {
            return true;
        }

        return ($comment->depth + 1) <= $max;
    }

    private function threadAcceptsReplies(Comment $comment): bool
    {
        if (array_key_exists($comment->thread_id, $this->replyAvailabilityByThread)) {
            return $this->replyAvailabilityByThread[$comment->thread_id];
        }

        $repository = app(CommentRepository::class);
        $entry = $repository->getCommentEntry($comment);

        return $this->replyAvailabilityByThread[$comment->thread_id] = $entry !== null
            && $repository->areCommentsEnabledForEntry($entry);
    }

    /** @return list<Comment> */
    private function resolveChildren(Comment $comment): array
    {
        foreach (['allChildren', 'children'] as $relation) {
            if ($comment->relationLoaded($relation)) {
                $children = $comment->getRelation($relation);

                $values = is_array($children)
                    ? $children
                    : ($children instanceof Collection ? $children->all() : []);

                return array_values(array_filter($values, fn (mixed $child): bool => $child instanceof Comment));
            }
        }

        return [];
    }

    private function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }
}
