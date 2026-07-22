<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Export;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Exporters\CsvExporter;
use Stillat\Meerkat\Exporters\JsonExporter;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExporterTest extends TestCase
{
    #[Test]
    public function csv_export_includes_canonical_columns(): void
    {
        $comment = CommentFactory::new()
            ->threadId('csv-thread')
            ->author('Alice', 'alice@example.com')
            ->text('Hello')
            ->data(['comment' => 'Hello'])
            ->published()
            ->create();

        $output = (new CsvExporter)
            ->setConfig([])
            ->setComments([$this->requireValue($comment->fresh())])
            ->export();

        $rows = array_map(str_getcsv(...), explode("\n", trim($output)));

        $canonical = [
            'id', 'thread_id', 'parent_id', 'depth', 'comment_text',
            'author_id', 'author_name', 'author_email',
            'is_published', 'is_spam', 'is_ham',
            'moderation_status', 'moderation_reason', 'moderation_notes',
            'moderated_at', 'moderated_by', 'site', 'collection',
            'created_at', 'updated_at',
        ];

        $this->assertSame($canonical, array_slice($rows[0], 0, count($canonical)));

        $this->assertCount(2, $rows);
        $this->assertSame('Hello', $rows[1][array_search('comment_text', $rows[0], true)]);
        $this->assertSame('Alice', $rows[1][array_search('author_name', $rows[0], true)]);
        $this->assertSame('csv-thread', $rows[1][array_search('thread_id', $rows[0], true)]);
        $this->assertSame('1', $rows[1][array_search('is_published', $rows[0], true)]);
    }

    #[Test]
    public function csv_export_neutralizes_formula_injection_in_cells(): void
    {

        $comment = CommentFactory::new()
            ->threadId('csv-injection')
            ->author('=HYPERLINK("http://evil.example","click")', 'attacker@example.com')
            ->text('@SUM(1+1)')
            ->data(['comment' => '@SUM(1+1)', 'website' => '+1-555-0100'])
            ->published()
            ->create();

        $output = (new CsvExporter)
            ->setConfig([])
            ->setComments([$this->requireValue($comment->fresh())])
            ->export();

        $rows = array_map(str_getcsv(...), explode("\n", trim($output)));
        $headers = $rows[0];
        $data = $rows[1];

        $name = $data[array_search('author_name', $headers, true)];
        $body = $data[array_search('comment_text', $headers, true)];
        $website = $data[array_search('website', $headers, true)];

        $this->assertSame("'=HYPERLINK(\"http://evil.example\",\"click\")", $name);
        $this->assertSame("'@SUM(1+1)", $body);
        $this->assertSame("'+1-555-0100", $website);
    }

    #[Test]
    public function csv_display_mode_keeps_data_aligned_with_header_labels(): void
    {
        $comment = CommentFactory::new()
            ->threadId('csv-display')
            ->author('Bob', 'bob@example.com')
            ->text('Body')
            ->data(['comment' => 'Body'])
            ->published()
            ->create();

        $output = (new CsvExporter)
            ->setConfig(['headers' => 'display'])
            ->setComments([$this->requireValue($comment->fresh())])
            ->export();

        $rows = array_map(str_getcsv(...), explode("\n", trim($output)));

        $this->assertCount(2, $rows);
        $this->assertNotEmpty($rows[1][0]);
        $this->assertContains('Bob', $rows[1]);
    }

    #[Test]
    public function csv_export_appends_comment_data_columns(): void
    {
        $comment = CommentFactory::new()
            ->threadId('csv-data')
            ->author('Carol', 'carol@example.com')
            ->text('Body')
            ->data(['comment' => 'Body', 'website' => 'https://example.com'])
            ->published()
            ->create();

        $output = (new CsvExporter)
            ->setConfig([])
            ->setComments([$this->requireValue($comment->fresh())])
            ->export();

        $rows = array_map(str_getcsv(...), explode("\n", trim($output)));

        $headers = $rows[0];
        $this->assertContains('website', $headers);
        $this->assertSame('https://example.com', $rows[1][array_search('website', $headers, true)]);
    }

    #[Test]
    public function csv_export_streams_lazy_queries_with_blueprint_derived_columns(): void
    {
        CommentFactory::new()
            ->threadId('csv-lazy')
            ->author('First Author', 'first@example.com')
            ->text('First body')
            ->data(['comment' => 'First body', 'website' => 'https://one.example.com'])
            ->published()
            ->create();
        CommentFactory::new()
            ->threadId('csv-lazy')
            ->author('Second Author', 'second@example.com')
            ->text('Second body')
            ->data(['comment' => 'Second body'])
            ->published()
            ->create();

        $output = (new CsvExporter)
            ->setConfig([])
            ->setComments(Comment::query()->orderBy('comments.id')->lazy())
            ->export();

        $rows = array_map(str_getcsv(...), explode("\n", trim($output)));
        $headers = $rows[0];

        $this->assertSame(['comment', 'name', 'email', 'website'], array_slice($headers, 20));
        $this->assertCount(3, $rows);
        $this->assertSame('First body', $rows[1][array_search('comment_text', $headers, true)]);
        $this->assertSame('Second body', $rows[2][array_search('comment_text', $headers, true)]);
        $this->assertSame('https://one.example.com', $rows[1][array_search('website', $headers, true)]);
        $this->assertSame('', $rows[2][array_search('website', $headers, true)]);
    }

    #[Test]
    public function json_export_uses_iso_timestamps(): void
    {
        $comment = CommentFactory::new()
            ->threadId('json-thread')
            ->author('Dan', 'dan@example.com')
            ->text('Json comment')
            ->data(['comment' => 'Json comment'])
            ->published()
            ->create();

        $payload = $this->requireObject(json_decode((new JsonExporter)
            ->setConfig([])
            ->setComments([$this->requireValue($comment->fresh())])
            ->export(), true));
        $comments = $this->requireRows($payload['comments']);

        $this->assertCount(1, $comments);
        $this->assertSame('Dan', $comments[0]['author_name']);
        $this->assertSame('json-thread', $comments[0]['thread_id']);
        $this->assertIsString($comments[0]['created_at']);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $comments[0]['created_at']);
    }

    #[Test]
    public function csv_download_writes_a_file_and_returns_a_streamed_response(): void
    {
        $comment = CommentFactory::new()
            ->threadId('csv-download')
            ->author('Faye', 'faye@example.com')
            ->text('Round trip')
            ->data(['comment' => 'Round trip'])
            ->published()
            ->create();

        $response = (new CsvExporter)
            ->setConfig([])
            ->setComments([$this->requireValue($comment->fresh())])
            ->download();

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv', $response->headers->get('Content-Type'));
        $this->assertNotEmpty(file_get_contents($response->getFile()->getPathname()));
    }

    #[Test]
    public function json_download_writes_a_file_and_returns_a_streamed_response(): void
    {
        $comment = CommentFactory::new()
            ->threadId('json-download')
            ->author('Gus', 'gus@example.com')
            ->text('Round trip')
            ->data(['comment' => 'Round trip'])
            ->published()
            ->create();

        $response = (new JsonExporter)
            ->setConfig([])
            ->setComments([$this->requireValue($comment->fresh())])
            ->download();

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $contents = file_get_contents($response->getFile()->getPathname());
        $this->assertIsString($contents);
        $payload = $this->requireObject(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
        $comments = $this->requireRows($payload['comments']);
        $this->assertCount(1, $comments);
        $this->assertSame('Gus', $comments[0]['author_name']);
    }
}
