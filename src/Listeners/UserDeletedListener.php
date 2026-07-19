<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Listeners;

use Statamic\Contracts\Auth\User;
use Statamic\Events\UserDeleted;
use Stillat\Meerkat\Database\Models\UserMeta;

class UserDeletedListener
{
    public function handle(UserDeleted $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            throw new \UnexpectedValueException('UserDeleted supplied an invalid user.');
        }

        /** @var UserMeta|null $userMeta */
        $userMeta = UserMeta::query()->where('user_id', $user->id())->first();

        $userMeta?->delete();
    }
}
