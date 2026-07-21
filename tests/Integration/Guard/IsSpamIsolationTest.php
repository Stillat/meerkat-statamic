<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Guard;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Guard\Guards\WordListGuard;
use Stillat\Meerkat\Guard\Manager;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class IsSpamIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Settings::set('wordlist.banned', ['casino']);
        config(['meerkat.spam.guards' => [ThrowingIsSpamGuard::class, WordListGuard::class]]);
    }

    #[Test]
    public function a_failing_guard_does_not_prevent_a_later_guard_from_flagging_spam(): void
    {
        $entry = $this->createEntry(['id' => 'isolation-spam']);
        $comment = CommentFactory::new()->threadId('isolation-spam')->text('Visit our casino now')->create();

        $this->assertTrue(app(Manager::class)->isSpam($entry, $comment));
    }

    #[Test]
    public function the_first_guard_failure_is_rethrown_when_no_guard_flags_spam(): void
    {
        $entry = $this->createEntry(['id' => 'isolation-ham']);
        $comment = CommentFactory::new()->threadId('isolation-ham')->text('A friendly reply')->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('isSpam exploded');

        app(Manager::class)->isSpam($entry, $comment);
    }
}

class ThrowingIsSpamGuard implements SpamGuard
{
    public function isSpam(Entry $entry, Comment $comment): bool
    {
        throw new RuntimeException('isSpam exploded');
    }

    public function reportSpam(Entry $entry, Comment $comment): void {}

    public function reportHam(Entry $entry, Comment $comment): void {}
}
