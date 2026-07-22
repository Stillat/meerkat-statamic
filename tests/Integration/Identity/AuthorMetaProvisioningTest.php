<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Identity;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User;
use Stillat\Meerkat\Concerns\ExtractsFields;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Tests\TestCase;

class AuthorMetaProvisioningTest extends TestCase
{
    #[Test]
    public function provisioning_creates_missing_meta_and_restores_existing_soft_deleted_meta(): void
    {
        $provisioner = new class
        {
            use ExtractsFields;

            public function provision(User $user): void
            {
                $this->ensureAuthorMetaDataExists($user);
            }
        };

        $freshUser = $this->makeStatamicUser();
        $freshUser->id('fresh-user');
        $freshUser->email('fresh@example.com');
        $freshUser->data(['name' => 'Fresh']);
        $provisioner->provision($freshUser);
        $this->assertSame('fresh@example.com', UserMeta::query()->where('user_id', 'fresh-user')->value('email'));

        $meta = UserMeta::create(['user_id' => 'soft-user', 'email' => 'old@example.com', 'name' => 'Old']);
        $meta->delete();
        $softUser = $this->makeStatamicUser();
        $softUser->id('soft-user');
        $softUser->email('new@example.com');
        $softUser->data(['name' => 'New']);
        $provisioner->provision($softUser);

        $this->assertSame(1, UserMeta::withTrashed()->where('user_id', 'soft-user')->count());
        $restored = UserMeta::query()->where('user_id', 'soft-user')->firstOrFail();
        $this->assertSame('new@example.com', $restored->email);
        $this->assertNull($restored->deleted_at);
    }
}
