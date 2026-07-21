<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions\Concerns;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Collection;
use Stillat\Meerkat\Database\Models\Comment;

trait AuthorizesCommentActions
{
    abstract protected function permission(): string;

    /** @param mixed $item */
    public function visibleTo($item): bool
    {
        return $this->authorize(auth()->user(), $item);
    }

    /** @param Collection<int, Comment> $items */
    public function visibleToBulk($items): bool
    {
        return $this->userCanRunAction(auth()->user());
    }

    /**
     * @param  mixed  $user
     * @param  mixed  $item
     */
    public function authorize($user, $item): bool
    {
        return $item instanceof Comment && $this->userCanRunAction($user);
    }

    /**
     * @param  mixed  $user
     * @param  Collection<int, Comment>  $items
     */
    public function authorizeBulk($user, $items): bool
    {
        return $items->every(fn ($item) => $this->authorize($user, $item));
    }

    protected function userCanRunAction(mixed $user): bool
    {
        return $user instanceof Authorizable && $user->can($this->permission());
    }
}
