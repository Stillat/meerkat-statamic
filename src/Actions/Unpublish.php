<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Stillat\Meerkat\Actions\Concerns\ReportsBulkOutcome;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;

class Unpublish extends Action
{
    use ReportsBulkOutcome;

    public function __construct(
        protected CommentRepository $comments
    ) {
        parent::__construct();
    }

    public static function title(): string
    {
        return __('meerkat::general.unpublish_comment');
    }

    public function confirmationText(): string
    {
        return __('meerkat::general.unpublish_comment_confirmation');
    }

    public function buttonText(): string
    {
        return __('meerkat::general.unpublish_comment_button');
    }

    /** @param Collection<int, Comment> $items */
    public function visibleToBulk($items): bool
    {
        return auth()->user()?->can('edit comments') ?? false;
    }

    /** @param mixed $item */
    public function visibleTo($item): bool
    {
        if (! $item instanceof Comment) {
            return false;
        }

        if (! auth()->user()?->can('edit comments')) {
            return false;
        }

        return $item->is_published;
    }

    /**
     * @param  Collection<int, Comment>  $items
     * @param  array<string, mixed>  $values
     */
    public function run($items, $values): string
    {
        $succeeded = $items->filter(fn ($comment) => $this->comments->unpublish($comment->id))->count();

        return $this->reportBulkOutcome($succeeded, $items->count(), 'meerkat::general.unpublished_comment');
    }
}
