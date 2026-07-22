<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Guard\Guards;

use DOMDocument;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Markdown;
use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;

class DeceptiveMarkupGuard implements SpamGuard
{
    public function isSpam(Entry $entry, Comment $comment): bool
    {
        if (mb_strlen(trim($comment->comment_text)) === 0) {
            return false;
        }

        $document = new DOMDocument;

        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(Markdown::parse($comment->comment_text));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }

        $htmlLinks = $document->getElementsByTagName('a');

        if ($htmlLinks->length === 0) {
            return false;
        }

        for ($i = 0; $i < $htmlLinks->length; $i++) {
            $link = $htmlLinks->item($i);

            if ($link !== null && mb_strlen(trim($link->textContent)) === 0) {
                return true;
            }
        }

        return false;
    }

    public function reportHam(Entry $entry, Comment $comment): void {}

    public function reportSpam(Entry $entry, Comment $comment): void {}
}
