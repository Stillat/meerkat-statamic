<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;

class CheckForSpam extends Action
{
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

    /** @param Collection<int, Comment> $items */
    public function visibleToBulk($items): bool
    {
        return auth()->user()?->can('check comment spam') ?? false;
    }

    /** @param mixed $item */
    public function visibleTo($item): bool
    {
        if (! $item instanceof Comment) {
            return false;
        }

        if (! auth()->user()?->can('check comment spam')) {
            return false;
        }

        return ! $item->checked_for_spam || ! $item->is_spam;
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
