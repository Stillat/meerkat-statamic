<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Statamic\Facades\Entry;
use Stillat\Meerkat\Database\Models\Thread;

class ThreadExistsRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Thread::query()->where('thread_id', $value)->exists()) {
            return;
        }

        if (Entry::find($value)) {
            return;
        }

        $fail(__('meerkat::validation.thread_exists'));
    }
}
