<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Stillat\Meerkat\Actions\Concerns\AuthorizesCommentActions;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;

class RemoveCommentSubtree extends Action
{
    use AuthorizesCommentActions;

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

    protected function permission(): string
    {
        return 'delete comments';
    }

    /** @param mixed $item */
    public function visibleTo($item): bool
    {
        return $item instanceof Comment
            && $this->authorize(auth()->user(), $item)
            && ! (bool) $item->is_removed
            && $item->children()->exists();
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
