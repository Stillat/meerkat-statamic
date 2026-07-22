<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Mirror\CommentParser;
use Stillat\Meerkat\Mirror\CommentSerializer;
use Stillat\Meerkat\Tests\TestCase;

class CommentSerializerTest extends TestCase
{
    #[Test]
    public function it_renders_a_comment_to_v3_frontmatter_with_a_body(): void
    {
        $comment = $this->createComment([
            'timestamp_id' => '1779039555',
            'author_name' => 'Maya Chen',
            'author_email' => 'maya@example.com',
            'user_ip' => '192.0.2.88',
            'user_agent' => 'Mozilla/5.0',
            'referer' => 'http://example.test/post',
            'comment_text' => "Body of the comment.\n\nWith two paragraphs.",
            'is_published' => true,
            'is_spam' => false,
        ]);

        $output = CommentSerializer::toString($comment);

        $this->assertStringStartsWith("---\n", $output);
        $this->assertStringContainsString("id: '1779039555'", $output);
        $this->assertStringContainsString("name: 'Maya Chen'", $output);
        $this->assertStringContainsString('published: true', $output);
        $this->assertStringContainsString('spam: false', $output);
        $this->assertStringContainsString('internal_author_has_name: true', $output);
        $this->assertStringContainsString('internal_author_has_email: true', $output);
        $this->assertStringContainsString("Body of the comment.\n\nWith two paragraphs.", $output);
    }

    #[Test]
    public function it_falls_back_to_the_created_at_timestamp_when_timestamp_id_is_missing(): void
    {
        $comment = $this->createComment([
            'timestamp_id' => null,
            'comment_text' => 'x',
        ]);
        $comment->created_at = Carbon::createFromTimestampUTC(1700000000);

        $output = CommentSerializer::toString($comment);

        $this->assertStringContainsString("id: '1700000000'", $output);
    }

    #[Test]
    public function it_serializes_moderation_provenance_so_a_rebuild_does_not_blank_it(): void
    {
        $comment = $this->createComment([
            'timestamp_id' => '1779000001',
            'comment_text' => 'rejected body',
            'is_published' => false,
            'is_ham' => true,
            'checked_for_spam' => true,
            'moderation_status' => 'rejected',
            'moderation_reason' => 'off-topic',
            'moderation_notes' => 'handled by jane',
        ]);

        $output = CommentSerializer::toString($comment);
        $parsed = CommentParser::parse($output);

        $this->assertSame('rejected', $parsed['frontmatter']['moderation_status']);
        $this->assertSame('off-topic', $parsed['frontmatter']['moderation_reason']);
        $this->assertSame('handled by jane', $parsed['frontmatter']['moderation_notes']);
        $this->assertTrue($parsed['frontmatter']['ham']);
        $this->assertTrue($parsed['frontmatter']['checked_for_spam']);
    }

    #[Test]
    public function array_extras_in_comment_data_round_trip_through_serialize_and_parse(): void
    {
        $comment = $this->createComment([
            'timestamp_id' => '1779039556',
            'comment_text' => 'hello',
            'comment_data' => [
                'choices' => ['alpha', 'beta'],
                'ratings' => ['clarity' => 5, 'tone' => 3],
                'nested' => [['label' => 'first'], ['label' => 'second']],
            ],
        ]);

        $output = CommentSerializer::toString($comment);
        $parsed = CommentParser::parse($output);

        $this->assertSame(['alpha', 'beta'], $parsed['frontmatter']['choices']);
        $this->assertSame(['clarity' => 5, 'tone' => 3], $parsed['frontmatter']['ratings']);
        $this->assertSame([['label' => 'first'], ['label' => 'second']], $parsed['frontmatter']['nested']);
        $this->assertSame('hello', $parsed['body']);
    }

    #[Test]
    public function multi_line_string_values_round_trip_without_losing_newlines(): void
    {
        $comment = $this->createComment([
            'timestamp_id' => '1779039557',
            'comment_text' => 'hello',
            'moderation_notes' => "first line\nsecond line",
            'comment_data' => [
                'address' => "12 Main St\nSpringfield",
                'sneaky' => "before\n--- after",
            ],
        ]);

        $output = CommentSerializer::toString($comment);
        $parsed = CommentParser::parse($output);

        $this->assertSame("first line\nsecond line", $parsed['frontmatter']['moderation_notes']);
        $this->assertSame("12 Main St\nSpringfield", $parsed['frontmatter']['address']);
        $this->assertSame("before\n--- after", $parsed['frontmatter']['sneaky']);
        $this->assertSame('hello', $parsed['body']);
    }

    #[Test]
    public function extras_in_comment_data_round_trip_through_serialize_and_parse(): void
    {
        $comment = $this->createComment([
            'timestamp_id' => '1779039555',
            'author_name' => 'A',
            'author_email' => 'a@example.com',
            'comment_text' => 'hello',
            'comment_data' => [
                'page_url' => 'http://site.test/post',
                'custom_field' => 'preserve me',
            ],
        ]);

        $output = CommentSerializer::toString($comment);
        $parsed = CommentParser::parse($output);

        $this->assertSame('http://site.test/post', $parsed['frontmatter']['page_url']);
        $this->assertSame('preserve me', $parsed['frontmatter']['custom_field']);
        $this->assertSame('hello', $parsed['body']);
    }
}
