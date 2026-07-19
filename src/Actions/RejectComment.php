<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions;

use Illuminate\Support\Collection;
use Statamic\Actions\Action;
use Stillat\Meerkat\Actions\Concerns\ReportsBulkOutcome;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;

class RejectComment extends Action
{
    use ReportsBulkOutcome;

    public function __construct(
        protected CommentRepository $comments
    ) {
        parent::__construct();
    }

    public static function title(): string
    {
        return __('meerkat::general.reject_comment');
    }

    public function confirmationText(): string
    {
        return __('meerkat::general.reject_comment_confirmation');
    }

    public function buttonText(): string
    {
        return __('meerkat::general.reject_comment_button');
    }

    /** @return array<string, array<string, string>> */
    public function fieldItems(): array
    {
        return [
            'reason' => [
                'type' => 'textarea',
                'display' => __('meerkat::fields.moderation_reason'),
                'instructions' => __('meerkat::general.reject_reason_instructions'),
            ],
        ];
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

        return (auth()->user()?->can('edit comments') ?? false)
            && $item->moderation_status !== 'rejected';
    }

    /**
     * @param  Collection<int, Comment>  $items
     * @param  array<string, mixed>  $values
     */
    public function run($items, $values): string
    {
        $reason = $values['reason'] ?? null;
        $reason = is_string($reason) ? $reason : null;
        $succeeded = $items->filter(fn ($comment) => $this->comments->reject($comment->id, $reason))->count();

        return $this->reportBulkOutcome($succeeded, $items->count(), 'meerkat::general.rejected_comment');
    }
}
