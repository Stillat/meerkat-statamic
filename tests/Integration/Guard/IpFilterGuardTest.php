<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Guard;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Guard\Guards\IpFilterGuard;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class IpFilterGuardTest extends TestCase
{
    #[Test]
    public function it_flags_the_stored_comment_ip_regardless_of_the_current_request_address(): void
    {
        Settings::set('iplist.block', ['198.51.100.7']);
        $entry = $this->createEntry(['id' => 'ip-stored']);
        $guard = new IpFilterGuard;

        request()->server->set('REMOTE_ADDR', '203.0.113.5');

        $this->assertTrue($guard->isSpam($entry, CommentFactory::new()->requestMetadata(ip: '198.51.100.7')->create()));
    }

    #[Test]
    public function it_abstains_when_no_ip_was_stored_even_if_the_request_ip_is_blocked(): void
    {
        Settings::set('iplist.block', ['198.51.100.7']);
        $entry = $this->createEntry(['id' => 'ip-null']);
        $guard = new IpFilterGuard;

        request()->server->set('REMOTE_ADDR', '198.51.100.7');

        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->requestMetadata(ip: null)->create()));
    }
}
