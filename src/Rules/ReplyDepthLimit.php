<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Stillat\Meerkat\Database\Models\Comment;

class ReplyDepthLimit implements ValidationRule
{
    public function __construct(
        protected ?Comment $parentComment,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $max = config('meerkat.publishing.max_reply_depth');
        $max = is_int($max)
            ? $max
            : (is_string($max) && is_numeric($max) ? (int) $max : 0);

        if ($max <= 0) {
            return;
        }

        if (! $this->parentComment instanceof Comment) {
            return;
        }

        $newDepth = $this->parentComment->depth + 1;

        if ($newDepth <= $max) {
            return;
        }

        $fail(__('meerkat::validation.reply_depth_limit', ['max' => $max]));
    }
}
