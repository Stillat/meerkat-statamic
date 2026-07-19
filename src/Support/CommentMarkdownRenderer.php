<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Statamic\Facades\Markdown;
use Tiptap\Marks\Link as TiptapLink;

class CommentMarkdownRenderer
{
    private const REMOVE_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form',
        'input', 'textarea', 'button', 'select', 'option', 'link',
        'meta', 'base', 'svg', 'math', 'applet', 'frame', 'frameset',
        'noscript',
    ];

    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'a', 'img',
        'em', 'strong', 'b', 'i', 'u', 's', 'strike', 'del', 'ins', 'sub', 'sup', 'small', 'mark',
        'code', 'pre', 'kbd', 'samp', 'var',
        'blockquote', 'q', 'cite',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'span', 'abbr', 'wbr',
    ];

    private const ALLOWED_ATTRIBUTES = ['href', 'src', 'alt', 'title'];

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function render(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $rendered = Markdown::parse($this->preserveSingleLineBreaks($text));

        if (trim($rendered) === '') {
            return '';
        }

        return $this->sanitize($rendered);
    }

    private function preserveSingleLineBreaks(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return preg_replace('/(?<!\n)\n(?!\n)/', "  \n", $text) ?? $text;
    }

    private function sanitize(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="meerkat-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('meerkat-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->stripTagsWithContent($document);
        $this->stripComments($document);
        $this->unwrapDisallowedTags($document, $root);
        $this->scrubAttributes($document);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    private function stripTagsWithContent(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach (self::REMOVE_WITH_CONTENT as $tag) {
            $nodes = $xpath->query('//'.$tag);

            if ($nodes === false) {
                continue;
            }

            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);

                if ($node instanceof \DOMNode) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }
    }

    private function stripComments(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $comments = $xpath->query('//comment()');

        if ($comments === false) {
            return;
        }

        for ($i = $comments->length - 1; $i >= 0; $i--) {
            $node = $comments->item($i);

            if ($node instanceof DOMComment) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function unwrapDisallowedTags(DOMDocument $document, DOMElement $root): void
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*');

        if ($nodes === false) {
            return;
        }

        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        foreach ($elements as $element) {
            if ($element === $root || in_array(strtolower($element->nodeName), self::ALLOWED_TAGS, true)) {
                continue;
            }

            while ($element->firstChild instanceof \DOMNode) {
                $element->parentNode?->insertBefore($element->firstChild, $element);
            }

            $element->parentNode?->removeChild($element);
        }
    }

    private function scrubAttributes(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $elements = $xpath->query('//*');

        if ($elements === false) {
            return;
        }

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $this->scrubElement($element);
        }
    }

    private function scrubElement(DOMElement $element): void
    {
        $toRemove = [];

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue ?? '';

            if (! in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                $toRemove[] = $attribute->nodeName;

                continue;
            }

            if (($name === 'href' || $name === 'src') && ! $this->isSafeUrl($value, $name)) {
                $toRemove[] = $attribute->nodeName;
            }
        }

        foreach ($toRemove as $name) {
            $element->removeAttribute($name);
        }

        if (strtolower($element->nodeName) === 'a') {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer nofollow ugc');
        }
    }

    private function isSafeUrl(string $value, string $attribute): bool
    {

        $trimmed = preg_replace(TiptapLink::ATTR_WHITESPACE, '', ltrim($value)) ?? '';

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '?')) {
            return true;
        }

        if (preg_match('/^data:image\/(png|jpe?g|gif|webp|svg\+xml);/i', $trimmed)) {

            return $attribute === 'src' && ! str_contains(strtolower($trimmed), 'svg+xml');
        }

        $firstColon = strpos($trimmed, ':');
        $firstDelimiter = strcspn($trimmed, '/?#');

        if ($firstColon !== false && $firstColon < $firstDelimiter) {
            if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $trimmed, $match)) {
                return in_array(strtolower($match[1]), self::ALLOWED_SCHEMES, true);
            }

            return false;
        }

        return true;
    }
}
