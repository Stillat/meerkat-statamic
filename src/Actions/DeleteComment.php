<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Stillat\Meerkat\Actions\Concerns\ReportsBulkOutcome;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;

class DeleteComment extends Action
{
    use ReportsBulkOutcome;

    /** @var bool */
    protected $dangerous = true;

    public function __construct(
        protected CommentRepository $comments
    ) {
        parent::__construct();
    }

    public static function title(): string
    {
        return __('meerkat::general.delete_comment');
    }

    public function confirmationText(): string
    {
        return __('meerkat::general.delete_comment_confirmation');
    }

    public function buttonText(): string
    {
        return __('meerkat::general.delete_comment_button');
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

        return (bool) auth()->user()?->can('delete comments');
    }

    /**
     * @param  Collection<int, Comment>  $items
     * @param  array<string, mixed>  $values
     */
    public function run($items, $values): string
    {
        $succeeded = $items->filter(fn ($comment) => $this->comments->deleteComment($comment->id))->count();

        return $this->reportBulkOutcome($succeeded, $items->count(), 'meerkat::general.deleted_comment');
    }
}
