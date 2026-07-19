<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Unit\Mirror;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stillat\Meerkat\Mirror\CommentParser;
use Stillat\Meerkat\Tests\UnitTestCase;

class CommentParserTest extends UnitTestCase
{
    #[Test]
    public function it_parses_frontmatter_and_preserves_bodies_across_legacy_encodings(): void
    {
        $standard = CommentParser::parse(<<<'MD'
        ---
        id: '1779039555'
        name: 'Maya Chen'
        email: 'maya@example.com'
        published: true
        spam: false
        ---
        First paragraph.

        Second paragraph.
        MD);
        $bom = CommentParser::parse("\xEF\xBB\xBF---\nid: '1'\npublished: true\nspam: false\n---\nbody\n");
        $crlf = CommentParser::parse("---\r\nid: '2'\r\npublished: true\r\nspam: false\r\n---\r\nbody\r\n");

        $this->assertSame('1779039555', $standard['frontmatter']['id']);
        $this->assertSame('Maya Chen', $standard['frontmatter']['name']);
        $this->assertTrue($standard['frontmatter']['published']);
        $this->assertFalse($standard['frontmatter']['spam']);
        $this->assertSame("First paragraph.\n\nSecond paragraph.", $standard['body']);
        $this->assertSame('1', $bom['frontmatter']['id']);
        $this->assertSame("body\n", $bom['body']);
        $this->assertSame('2', $crlf['frontmatter']['id']);
        $this->assertSame("body\n", $crlf['body']);
    }

    #[Test]
    public function it_rejects_missing_frontmatter_boundaries(): void
    {
        foreach ([
            ["id: 1\nbody\n", 'opening `---`'],
            ["---\nid: 1\nname: x\n", 'closing `---`'],
        ] as [$contents, $message]) {
            try {
                CommentParser::parse($contents);
                $this->fail("Expected parser failure for {$message}");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }
}
