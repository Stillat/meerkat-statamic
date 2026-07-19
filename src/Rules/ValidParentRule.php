<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Support\CommentVisibility;

class ValidParentRule implements ValidationRule
{
    public function __construct(
        protected ?string $entryId,
        protected ?Comment $parentComment,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->parentComment instanceof Comment) {
            $fail(__('meerkat::validation.parent_exists'));

            return;
        }

        if ($this->parentComment->thread_id !== $this->entryId) {
            $fail(__('meerkat::validation.parent_same_thread'));

            return;
        }

        $visibility = app(CommentVisibility::class);

        if ($visibility->isPublicVisible($this->parentComment)
            || $visibility->canViewModerationForComment($this->parentComment)) {
            return;
        }

        $fail(__('meerkat::validation.parent_visible'));
    }
}
