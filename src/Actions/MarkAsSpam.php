<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Stillat\Meerkat\Actions\Concerns\AuthorizesCommentActions;
use Stillat\Meerkat\Actions\Concerns\ReportsBulkOutcome;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;

class MarkAsSpam extends Action
{
    use AuthorizesCommentActions, ReportsBulkOutcome;

    public function __construct(
        protected CommentRepository $comments
    ) {
        parent::__construct();
    }

    public static function title(): string
    {
        return __('meerkat::general.mark_as_spam');
    }

    public function confirmationText(): string
    {
        return __('meerkat::general.mark_as_spam_confirmation');
    }

    public function buttonText(): string
    {
        return __('meerkat::general.mark_as_spam_button');
    }

    protected function permission(): string
    {
        return 'report comment spam';
    }

    /** @param mixed $item */
    public function visibleTo($item): bool
    {
        return $item instanceof Comment
            && $this->authorize(auth()->user(), $item)
            && ! $item->is_ham
            && ! $item->is_spam;
    }

    /**
     * @param  Collection<int, Comment>  $items
     * @param  array<string, mixed>  $values
     */
    public function run($items, $values): string
    {
        $succeeded = $items->filter(fn ($comment) => $this->comments->markAsSpam($comment->id))->count();

        return $this->reportBulkOutcome($succeeded, $items->count(), 'meerkat::general.marked_as_spam');
    }
}
