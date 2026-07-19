<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;

class RestoreComment extends Action
{
    public function __construct(
        protected CommentRepository $comments
    ) {
        parent::__construct();
    }

    public static function title(): string
    {
        return __('meerkat::general.restore_comment');
    }

    public function confirmationText(): string
    {
        return __('meerkat::general.restore_comment_confirmation');
    }

    public function buttonText(): string
    {
        return __('meerkat::general.restore_comment_button');
    }

    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     */
    public function visibleToBulk($items): bool
    {
        if (! auth()->user()?->can('delete comments')) {
            return false;
        }

        return $items->contains(fn ($item) => $item instanceof Comment && (bool) $item->is_removed);
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

        return (bool) $item->is_removed;
    }

    /**
     * @param  Collection<int, Comment>  $items
     * @param  array<string, mixed>  $values
     */
    public function run($items, $values): string
    {
        $count = $items->filter(fn ($comment) => $this->comments->restoreComment($comment->id))->count();

        return trans_choice('meerkat::general.restored_comment', $count);
    }
}
