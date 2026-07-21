<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Stillat\Meerkat\Actions\Concerns\AuthorizesCommentActions;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;

class CheckForSpam extends Action
{
    use AuthorizesCommentActions;

    public function __construct(
        protected CommentRepository $comments
    ) {
        parent::__construct();
    }

    public static function title(): string
    {
        return __('meerkat::general.check_for_spam');
    }

    public function confirmationText(): string
    {
        return __('meerkat::general.check_for_spam_confirmation');
    }

    public function buttonText(): string
    {
        return __('meerkat::general.check_for_spam_button');
    }

    protected function permission(): string
    {
        return 'check comment spam';
    }

    /** @param mixed $item */
    public function visibleTo($item): bool
    {
        return $item instanceof Comment
            && $this->authorize(auth()->user(), $item)
            && (! $item->checked_for_spam || ! $item->is_spam);
    }

    /**
     * @param  Collection<int, Comment>  $items
     * @param  array<string, mixed>  $values
     */
    public function run($items, $values): string
    {
        $flagged = 0;

        foreach ($items as $comment) {
            $this->comments->checkForSpam($comment->id);

            if (Comment::query()->withTrashed()->whereKey($comment->id)->value('is_spam')) {
                $flagged++;
            }
        }

        return trans_choice('meerkat::general.spam_check_result', $items->count(), [
            'total' => $items->count(),
            'flagged' => $flagged,
        ]);
    }
}
