<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Permissions;

use Illuminate\Support\Collection;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Auth\Permission as RegisteredPermission;
use Statamic\Facades\Permission;
use Stillat\Meerkat\Tests\TestCase;

class RegistrationTest extends TestCase
{
    #[Test]
    public function meerkat_permissions_are_registered_with_the_expected_hierarchy_and_exact_keys(): void
    {
        $permissions = Permission::all();
        $keys = $permissions->keys()->all();
        foreach (['view comments', 'submit comments', 'report comment spam'] as $permission) {
            $this->assertContains($permission, $keys);
        }
        $this->assertNotContains('report comment spam ', $keys);
        $this->assertNotContains('permanently delete comments', $keys);

        $viewComments = $permissions->first(
            fn (mixed $permission): bool => $permission instanceof RegisteredPermission
                && $permission->value() === 'view comments'
        );
        $this->assertInstanceOf(RegisteredPermission::class, $viewComments);
        $children = $viewComments->children();

        if (! $children instanceof Collection) {
            throw new LogicException('Statamic did not return child permissions.');
        }

        $children = $children
            ->filter(fn (mixed $permission): bool => $permission instanceof RegisteredPermission)
            ->map(fn (RegisteredPermission $permission) => $permission->value())
            ->all();
        foreach (['edit comments', 'delete comments', 'check comment spam', 'report comment spam'] as $child) {
            $this->assertContains($child, $children);
        }
    }
}
