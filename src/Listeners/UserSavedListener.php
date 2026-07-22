<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Listeners;

use Statamic\Contracts\Auth\User;
use Statamic\Events\UserSaved;
use Stillat\Meerkat\Concerns\ExtractsFields;
use Stillat\Meerkat\Database\Models\UserMeta;

class UserSavedListener
{
    use ExtractsFields;

    public function handle(UserSaved $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            throw new \UnexpectedValueException('UserSaved supplied an invalid user.');
        }

        $details = $this->authorDetailsFromUser($user);

        /** @var UserMeta $userMeta */
        $userMeta = UserMeta::withTrashed()->firstOrNew([
            'user_id' => $user->id(),
        ]);

        $userMeta->email = $details['email'] ?? null;
        $userMeta->name = $details['name'] ?? null;
        $userMeta->deleted_at = null;
        $userMeta->save();
    }
}
