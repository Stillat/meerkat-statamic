<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Comments;

use Statamic\Fields\Value;
use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;

final class GuardedValue extends Value
{
    public function value(): mixed
    {
        return $this->evaluatingUserContent() ? null : parent::value();
    }

    public function raw(): mixed
    {
        return $this->evaluatingUserContent() ? null : parent::raw();
    }

    private function evaluatingUserContent(): bool
    {
        return GlobalRuntimeState::$isEvaluatingUserData
            && GlobalRuntimeState::$userContentEvalState !== null;
    }
}
