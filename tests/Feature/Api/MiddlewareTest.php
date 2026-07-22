<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class MiddlewareTest extends TestCase
{
    #[Test]
    public function privileged_group_requires_authentication_and_view_permission(): void
    {
        $groups = Route::getMiddlewareGroups();

        $this->assertArrayHasKey('meerkat-api-privileged', $groups);
        $group = $this->requireList($groups['meerkat-api-privileged'] ?? null);
        $this->assertContains('auth', $group);
        $this->assertContains('can:view comments', $group);
    }
}
