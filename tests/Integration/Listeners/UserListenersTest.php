<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Listeners;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\UserDeleted;
use Statamic\Events\UserSaved;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Listeners\UserDeletedListener;
use Stillat\Meerkat\Listeners\UserSavedListener;
use Stillat\Meerkat\Tests\TestCase;

class UserListenersTest extends TestCase
{
    #[Test]
    public function saved_listener_creates_and_updates_one_meta_row_per_user(): void
    {
        $user = $this->makeStatamicUser();
        $user->id('saved-user');
        $user->email('first@example.com');
        $user->data(['name' => 'First']);
        $listener = new UserSavedListener;
        $listener->handle(new UserSaved($user));

        $user->email('updated@example.com');
        $user->set('name', 'Updated');
        $listener->handle(new UserSaved($user));

        $this->assertSame(1, UserMeta::query()->where('user_id', 'saved-user')->count());
        $meta = UserMeta::query()->where('user_id', 'saved-user')->firstOrFail();
        $this->assertSame('updated@example.com', $meta->email);
        $this->assertSame('Updated', $meta->name);
    }

    #[Test]
    public function deleted_listener_soft_deletes_only_the_matching_meta_and_preserves_identity(): void
    {
        $deletedUser = $this->makeStatamicUser();
        $deletedUser->id('deleted-user');
        $deletedUser->email('deleted@example.com');
        $deletedUser->data(['name' => 'Deleted']);
        $otherUser = $this->makeStatamicUser();
        $otherUser->id('other-user');
        $otherUser->email('other@example.com');
        $otherUser->data(['name' => 'Other']);
        $saved = new UserSavedListener;
        $saved->handle(new UserSaved($deletedUser));
        $saved->handle(new UserSaved($otherUser));

        (new UserDeletedListener)->handle(new UserDeleted($deletedUser));

        $deleted = UserMeta::withTrashed()->where('user_id', 'deleted-user')->firstOrFail();
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame('deleted@example.com', $deleted->email);
        $this->assertSame('Deleted', $deleted->name);
        $this->assertNull(UserMeta::query()->where('user_id', 'other-user')->firstOrFail()->deleted_at);
    }

    #[Test]
    public function saved_listener_handles_missing_optional_name(): void
    {
        $user = $this->makeStatamicUser();
        $user->id('minimal-user');
        $user->email('minimal@example.com');

        (new UserSavedListener)->handle(new UserSaved($user));

        $meta = UserMeta::query()->where('user_id', 'minimal-user')->firstOrFail();
        $this->assertSame('minimal@example.com', $meta->email);
        $this->assertSame('minimal@example.com', $meta->name);
    }

    #[Test]
    public function saved_listener_restores_a_soft_deleted_meta_instead_of_duplicating_it(): void
    {
        $user = $this->makeStatamicUser();
        $user->id('restored-user');
        $user->email('restore@example.com');
        $user->data(['name' => 'Restore']);
        $listener = new UserSavedListener;
        $listener->handle(new UserSaved($user));
        UserMeta::query()->where('user_id', 'restored-user')->firstOrFail()->delete();

        $listener->handle(new UserSaved($user));

        $this->assertSame(1, UserMeta::withTrashed()->where('user_id', 'restored-user')->count());
        $this->assertNull(UserMeta::query()->where('user_id', 'restored-user')->firstOrFail()->deleted_at);
    }
}
