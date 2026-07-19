<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Identity;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ExportCommandTest extends TestCase
{
    #[Test]
    public function export_requires_an_identity(): void
    {
        $this->pendingArtisan('meerkat:export-identity')->expectsOutputToContain('Provide --email and/or --user-id')->assertExitCode(1);
    }

    #[Test]
    public function export_writes_resolved_subject_counts_rows_and_compliance_hash(): void
    {
        UserMeta::create(['user_id' => 'exp-1', 'email' => 'export@example.com', 'name' => 'Export']);
        CommentFactory::new()->threadId('exp-thread')->author('Export', 'export@example.com')->text('hello')->data(['comment' => 'hello'])->create();
        $out = $this->temporaryFilePath('meerkat-export-', '.json');

        $this->pendingArtisan('meerkat:export-identity', ['--email' => 'export@example.com', '--out' => $out])->assertExitCode(0);
        $contents = file_get_contents($out);
        $this->assertIsString($contents);
        $payload = $this->requireObject(json_decode($contents, true));
        $subject = $this->requireObject($payload['subject']);
        $counts = $this->requireObject($payload['counts']);
        $comments = $this->requireRows($payload['comments']);
        $this->assertSame('export@example.com', $subject['email']);
        $this->assertSame('exp-1', $subject['user_id']);
        $this->assertIsString($subject['subject_hash']);
        $this->assertSame(64, strlen($subject['subject_hash']));
        $this->assertSame(1, $counts['comments']);
        $this->assertSame(1, $counts['users_meta']);
        $this->assertSame('hello', $comments[0]['comment_text']);
    }

    #[Test]
    public function unmatched_identity_still_produces_an_empty_export(): void
    {
        $out = $this->temporaryFilePath('meerkat-empty-', '.json');
        $this->pendingArtisan('meerkat:export-identity', ['--email' => 'missing@example.com', '--out' => $out])->assertExitCode(0);
        $contents = file_get_contents($out);
        $this->assertIsString($contents);
        $payload = $this->requireObject(json_decode($contents, true));
        $counts = $this->requireObject($payload['counts']);
        $this->assertSame(0, $counts['comments']);
        $this->assertSame([], $this->requireList($payload['comments']));
    }
}
