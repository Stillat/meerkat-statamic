<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;

class RemoveCommentSubtree extends Action
{
    /** @var bool */
    protected $dangerous = true;

    public function __construct(
        protected CommentRepository $comments
    ) {
        parent::__construct();
    }

    public static function title(): string
    {
        return __('meerkat::general.remove_subtree');
    }

    public function confirmationText(): string
    {
        return __('meerkat::general.remove_subtree_confirmation');
    }

    public function warningText(): string
    {
        return __('meerkat::general.remove_subtree_warning');
    }

    public function buttonText(): string
    {
        return __('meerkat::general.remove_subtree_button');
    }

    /** @param Collection<int, Comment> $items */
    public function visibleToBulk($items): bool
    {
        return auth()->user()?->can('delete comments') ?? false;
    }

    /** @param mixed $item */
    public function visibleTo($item): bool
    {
        if (! $item instanceof Comment) {
            return false;
        }

        if (! auth()->user()?->can('delete comments')) {
            return false;
        }

        return ! (bool) $item->is_removed && $item->replies_count > 0;
    }

    /**
     * @param  Collection<int, Comment>  $items
     * @param  array<string, mixed>  $values
     */
    public function run($items, $values): string
    {
        $total = 0;
        foreach ($items as $comment) {
            $total += $this->comments->removeSubtree($comment->id);
        }

        return trans_choice('meerkat::general.removed_subtree', $total, ['count' => $total]);
    }
}
