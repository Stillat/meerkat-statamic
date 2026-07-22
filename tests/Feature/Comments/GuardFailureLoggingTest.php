<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Comments;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Tests\TestCase;

class GuardFailureLoggingTest extends TestCase
{
    #[Test]
    public function a_guard_failure_logs_only_scalar_identifiers_and_no_comment_model(): void
    {
        config(['meerkat.spam.guards' => [AlwaysThrowingGuard::class]]);
        $this->createEntry(['id' => 'guard-log']);

        /** @var list<array{level: string, message: string, context: array<string, mixed>}> $captured */
        $captured = [];

        Log::listen(function (MessageLogged $event) use (&$captured): void {
            if ($event->message === 'Meerkat: Checking for spam failed.') {
                $captured[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
            }
        });

        $this->submitComment([
            '_meerkat_context' => 'guard-log',
            'comment' => 'A perfectly normal comment.',
            'name' => 'Guest',
            'email' => 'guest@example.com',
        ])->assertRedirect();

        $this->assertCount(1, $captured);
        $this->assertSame('warning', $captured[0]['level']);
        $this->assertSame(['exception', 'comment_id', 'thread_id'], array_keys($captured[0]['context']));
        $this->assertIsString($captured[0]['context']['exception']);
    }
}

class AlwaysThrowingGuard implements SpamGuard
{
    public function isSpam(Entry $entry, Comment $comment): bool
    {
        throw new RuntimeException('guard exploded');
    }

    public function reportSpam(Entry $entry, Comment $comment): void {}

    public function reportHam(Entry $entry, Comment $comment): void {}
}
