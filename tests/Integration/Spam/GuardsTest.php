<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Spam;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Guard\Guards\DeceptiveMarkupGuard;
use Stillat\Meerkat\Guard\Guards\GtubeGuard;
use Stillat\Meerkat\Guard\Guards\IpFilterGuard;
use Stillat\Meerkat\Guard\Guards\WordListGuard;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class GuardsTest extends TestCase
{
    #[Test]
    public function word_list_guard_is_case_insensitive_and_allows_clean_text(): void
    {
        Settings::set('wordlist.banned', ['CASINO', 'viagra']);
        $entry = $this->createEntry(['id' => 'word-list']);
        $guard = new WordListGuard;

        $this->assertTrue($guard->isSpam($entry, CommentFactory::new()->text('Try our Casino games')->create()));
        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->text('A friendly reply')->create()));
    }

    #[Test]
    public function ip_filter_guard_partitions_blocked_and_unrelated_addresses(): void
    {
        Settings::set('iplist.block', ['192.0.2.1']);
        $entry = $this->createEntry(['id' => 'ip-filter']);
        $comment = CommentFactory::new()->create();
        $guard = new IpFilterGuard;

        request()->server->set('REMOTE_ADDR', '192.0.2.1');
        $this->assertTrue($guard->isSpam($entry, $comment));
        request()->server->set('REMOTE_ADDR', '203.0.113.5');
        $this->assertFalse($guard->isSpam($entry, $comment));
    }

    #[Test]
    public function gtube_guard_detects_the_standard_token_without_flagging_normal_text(): void
    {
        $entry = $this->createEntry(['id' => 'gtube']);
        $guard = new GtubeGuard;
        $spam = CommentFactory::new()->text('XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X')->create();
        $clean = CommentFactory::new()->text('A normal comment')->create();

        $this->assertTrue($guard->isSpam($entry, $spam));
        $this->assertFalse($guard->isSpam($entry, $clean));
    }

    #[Test]
    public function deceptive_markup_guard_detects_empty_link_labels_but_allows_normal_or_plain_text(): void
    {
        $entry = $this->createEntry(['id' => 'markup']);
        $guard = new DeceptiveMarkupGuard;

        $this->assertTrue($guard->isSpam($entry, CommentFactory::new()->text('[   ](https://malicious.example/)')->create()));
        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->text('[click here](https://example.com)')->create()));
        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->text('Plain comment')->create()));
    }
}
