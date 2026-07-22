<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Support\CommentMarkdownRenderer;
use Stillat\Meerkat\Tests\TestCase;

class MarkdownRendererTest extends TestCase
{
    private function render(?string $text): string
    {
        return app(CommentMarkdownRenderer::class)->render($text);
    }

    #[Test]
    public function it_returns_empty_string_for_null_or_blank_input(): void
    {
        $this->assertSame('', $this->render(null));
        $this->assertSame('', $this->render(''));
        $this->assertSame('', $this->render("   \n\t  "));
    }

    #[Test]
    public function it_renders_safe_markdown_formatting(): void
    {
        $output = $this->render('**bold** and *italic* and `code`');

        $this->assertStringContainsString('<strong>bold</strong>', $output);
        $this->assertStringContainsString('<em>italic</em>', $output);
        $this->assertStringContainsString('<code>code</code>', $output);
    }

    #[Test]
    public function it_preserves_single_line_breaks_as_br(): void
    {
        $output = $this->render("line one\nline two\nline three");

        $this->assertSame(2, substr_count($output, '<br'), 'Single newlines should become hard breaks.');
        $this->assertStringContainsString('line one', $output);
        $this->assertStringContainsString('line three', $output);
    }

    #[Test]
    public function it_keeps_blank_lines_as_separate_paragraphs(): void
    {
        $output = $this->render("first para\n\nsecond para");

        $this->assertSame(2, substr_count($output, '<p>'));
        $this->assertStringNotContainsString('<br', $output);
    }

    #[Test]
    public function it_strips_script_tags(): void
    {
        $output = $this->render("hello\n\n<script>alert('xss')</script>");

        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('alert', $output);
        $this->assertStringContainsString('hello', $output);
    }

    #[Test]
    public function it_strips_inline_event_handler_attributes(): void
    {
        $output = $this->render('<a href="https://example.com" onclick="alert(1)">click</a>');

        $this->assertStringNotContainsString('onclick', $output);
        $this->assertStringNotContainsString('alert(1)', $output);
        $this->assertStringContainsString('href="https://example.com"', $output);
    }

    #[Test]
    public function it_strips_javascript_pseudo_url_in_links(): void
    {
        $output = $this->render('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript:', $output);
        $this->assertStringNotContainsString('alert(1)', $output);
    }

    #[Test]
    public function it_strips_vbscript_pseudo_url_in_links(): void
    {
        $output = $this->render('<a href="VBScript:msgbox(1)">click</a>');

        $this->assertStringNotContainsString('vbscript', strtolower($output));
    }

    #[Test]
    public function it_strips_data_url_in_links(): void
    {
        $output = $this->render('<a href="data:text/html,<script>alert(1)</script>">click</a>');

        $this->assertStringNotContainsString('data:text/html', $output);
        $this->assertStringNotContainsString('alert', $output);
    }

    #[Test]
    public function it_rejects_inline_svg_data_uris_in_images(): void
    {
        $svg = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxzY3JpcHQ+YWxlcnQoMSk8L3NjcmlwdD48L3N2Zz4=';
        $output = $this->render('<img src="'.$svg.'" alt="x">');

        $this->assertStringNotContainsString('svg+xml', $output);
    }

    #[Test]
    public function it_allows_image_data_uris_for_safe_formats(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgo=';
        $output = $this->render('<img src="'.$png.'" alt="x">');

        $this->assertStringContainsString('data:image/png', $output);
    }

    #[Test]
    public function it_strips_iframe_tags(): void
    {
        $output = $this->render('<iframe src="https://evil.example/"></iframe>');

        $this->assertStringNotContainsString('iframe', $output);
        $this->assertStringNotContainsString('evil.example', $output);
    }

    #[Test]
    public function it_strips_form_and_input_tags(): void
    {
        $output = $this->render('<form action="/x"><input name="csrf" value="1"></form>');

        $this->assertStringNotContainsString('<form', $output);
        $this->assertStringNotContainsString('<input', $output);
    }

    #[Test]
    public function it_strips_style_attribute(): void
    {
        $output = $this->render('<p style="position:fixed;top:0">x</p>');

        $this->assertStringNotContainsString('style=', $output);
        $this->assertStringContainsString('<p>x</p>', $output);
    }

    #[Test]
    public function it_forces_safe_rel_and_target_on_links(): void
    {
        $output = $this->render('[example](https://example.com)');

        $this->assertMatchesRegularExpression('/<a [^>]*target="_blank"[^>]*>/', $output);
        $this->assertMatchesRegularExpression('/<a [^>]*rel="noopener noreferrer nofollow ugc"[^>]*>/', $output);
    }

    #[Test]
    public function it_preserves_relative_and_fragment_urls(): void
    {
        $output = $this->render('<a href="/local">local</a> <a href="#anchor">anchor</a>');

        $this->assertStringContainsString('href="/local"', $output);
        $this->assertStringContainsString('href="#anchor"', $output);
    }

    #[Test]
    public function it_preserves_mailto_and_tel_links(): void
    {
        $output = $this->render('<a href="mailto:a@example.com">mail</a> <a href="tel:+1234">call</a>');

        $this->assertStringContainsString('href="mailto:a@example.com"', $output);
        $this->assertStringContainsString('href="tel:+1234"', $output);
    }

    #[Test]
    public function it_strips_javascript_url_with_embedded_tab(): void
    {
        $output = $this->render("<a href=\"java\tscript:alert(1)\">click</a>");

        $this->assertStringNotContainsString('javascript', strtolower($output));
        $this->assertStringNotContainsString('alert(1)', $output);
    }

    #[Test]
    public function it_strips_javascript_url_with_embedded_newline(): void
    {
        $output = $this->render("<a href=\"java\nscript:alert(1)\">click</a>");

        $this->assertStringNotContainsString('javascript', strtolower($output));
        $this->assertStringNotContainsString('alert(1)', $output);
    }

    #[Test]
    public function it_strips_javascript_url_with_embedded_carriage_return(): void
    {
        $output = $this->render("<a href=\"java\rscript:alert(1)\">click</a>");

        $this->assertStringNotContainsString('javascript', strtolower($output));
        $this->assertStringNotContainsString('alert(1)', $output);
    }

    #[Test]
    public function it_strips_javascript_url_with_embedded_null_byte(): void
    {
        $output = $this->render("<a href=\"java\x00script:alert(1)\">click</a>");

        $this->assertStringNotContainsString('javascript', strtolower($output));
        $this->assertStringNotContainsString('alert(1)', $output);
    }

    #[Test]
    public function it_strips_javascript_url_with_html_entity_encoded_tab(): void
    {
        $output = $this->render('<a href="java&#9;script:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript', strtolower($output));
        $this->assertStringNotContainsString('alert(1)', $output);
    }

    #[Test]
    public function it_strips_javascript_url_with_mixed_control_bytes(): void
    {
        $output = $this->render("<a href=\"java\t \nscript:alert(1)\">click</a>");

        $this->assertStringNotContainsString('javascript', strtolower($output));
        $this->assertStringNotContainsString('alert(1)', $output);
    }

    #[Test]
    public function it_strips_html_comment_nodes(): void
    {
        $output = $this->render('before <!-- <img src=x onerror=alert(1)> --> after');

        $this->assertStringNotContainsString('<!--', $output);
        $this->assertStringNotContainsString('onerror', $output);
        $this->assertStringContainsString('before', $output);
        $this->assertStringContainsString('after', $output);
    }

    #[Test]
    public function it_drops_legacy_url_bearing_attributes(): void
    {
        $output = $this->render('<table background="javascript:alert(1)"><tr><td>cell</td></tr></table>');

        $this->assertStringNotContainsString('javascript', strtolower($output));
        $this->assertStringNotContainsString('background', $output);
        $this->assertStringContainsString('cell', $output);
    }

    #[Test]
    public function it_unwraps_unknown_tags_keeping_their_text(): void
    {
        $output = $this->render('<div onclick="alert(1)">kept text</div>');

        $this->assertStringNotContainsString('<div', $output);
        $this->assertStringNotContainsString('onclick', $output);
        $this->assertStringNotContainsString('alert(1)', $output);
        $this->assertStringContainsString('kept text', $output);
    }

    #[Test]
    public function it_drops_class_and_other_non_allowlisted_attributes(): void
    {
        $output = $this->render('<a href="https://example.com" class="evil" data-x="y">link</a>');

        $this->assertStringNotContainsString('class=', $output);
        $this->assertStringNotContainsString('data-x', $output);
        $this->assertStringContainsString('href="https://example.com"', $output);
    }
}
