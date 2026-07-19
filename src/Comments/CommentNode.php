<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Comments;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * @phpstan-type CurrentUser array{
 *     is_authenticated: bool,
 *     is_author: bool,
 *     can_edit: bool,
 *     can_delete: bool,
 *     can_reply: bool,
 *     can_report_spam: bool,
 * }
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class CommentNode implements Arrayable
{
    /**
     * @param  array<string, mixed>  $comment  Augmented comment fields
     * @param  Collection<int, CommentNode>  $children
     * @param  CurrentUser  $currentUser
     */
    public function __construct(
        public array $comment,
        public string $commentHtml,
        public string $gravatar,
        public string $anchor,
        public string $permalink,
        public int $depth,
        public int $repliesCount,
        public bool $hasReplies,
        public bool $isReply,
        public bool $isRoot,
        public bool $isRemoved,
        public bool $isDeleted,
        public Collection $children,
        public array $currentUser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->comment;

        $data['children'] = $this->children->map(fn (CommentNode $child) => $child->toArray())->all();

        $data['has_replies'] = $this->hasReplies;
        $data['replies_count'] = $this->repliesCount;
        $data['depth'] = $this->depth;
        $data['is_reply'] = $this->isReply;
        $data['is_root'] = $this->isRoot;
        $data['is_removed'] = $this->isRemoved;
        $data['is_deleted'] = $this->isDeleted;
        $data['comment_html'] = $this->commentHtml;
        $data['gravatar'] = $this->gravatar;
        $data['anchor'] = $this->anchor;
        $data['permalink'] = $this->permalink;
        $data['current_user'] = $this->currentUser;

        return $data;
    }
}
