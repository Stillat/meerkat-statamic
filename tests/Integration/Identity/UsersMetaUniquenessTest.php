<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Identity;

use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\UserSaved;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Listeners\UserSavedListener;
use Stillat\Meerkat\Tests\TestCase;

class UsersMetaUniquenessTest extends TestCase
{
    #[Test]
    public function user_id_has_a_database_unique_constraint(): void
    {
        UserMeta::create(['user_id' => 'duplicate', 'email' => 'a@example.com', 'name' => 'A']);
        $this->expectException(QueryException::class);
        UserMeta::create(['user_id' => 'duplicate', 'email' => 'b@example.com', 'name' => 'B']);
    }

    #[Test]
    public function user_saved_listener_updates_the_unique_row_in_place(): void
    {
        $user = $this->makeStatamicUser();
        $user->id('lifecycle-user');
        $user->email('initial@example.com');
        $user->data(['name' => 'Initial']);
        $listener = new UserSavedListener;
        $listener->handle(new UserSaved($user));
        $firstId = UserMeta::query()->where('user_id', 'lifecycle-user')->value('id');

        $user->email('updated@example.com');
        $user->set('name', 'Updated');
        $listener->handle(new UserSaved($user));

        $this->assertSame(1, UserMeta::query()->where('user_id', 'lifecycle-user')->count());
        $row = UserMeta::query()->where('user_id', 'lifecycle-user')->firstOrFail();
        $this->assertSame($firstId, $row->id);
        $this->assertSame('updated@example.com', $row->email);
        $this->assertSame('Updated', $row->name);
    }
}
