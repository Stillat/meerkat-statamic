<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Spam;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Guard\Guards\WordListGuard;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class WordListGuardEvasionTest extends TestCase
{
    #[Test]
    public function banned_words_are_matched_through_common_obfuscation(): void
    {
        Settings::set('wordlist.banned', ['spam']);
        $entry = $this->createEntry(['id' => 'evasion-word']);
        $guard = new WordListGuard;

        $cases = [
            'zero-width space' => "sp\u{200B}am",
            'zero-width non-joiner' => "sp\u{200C}am",
            'zero-width joiner' => "sp\u{200D}am",
            'word joiner' => "sp\u{2060}am",
            'byte order mark' => "sp\u{FEFF}am",
            'soft hyphen' => "sp\u{00AD}am",
            'bidi override' => "\u{202E}sp\u{202C}am",
            'full-width' => "\u{FF53}\u{FF50}\u{FF41}\u{FF4D}",
            'combining accent' => "spa\u{0301}m",
            'cyrillic homoglyphs' => "s\u{0440}\u{0430}m",
            'greek homoglyphs' => "s\u{03C1}\u{03B1}m",
            'uppercase' => 'SPAM',
        ];

        foreach ($cases as $label => $text) {
            $this->assertTrue(
                $guard->isSpam($entry, CommentFactory::new()->text("a {$text} comment")->create()),
                "expected the '{$label}' variant to be flagged"
            );
        }
    }

    #[Test]
    public function banned_phrases_survive_whitespace_and_invisible_tricks(): void
    {
        Settings::set('wordlist.banned', ['buy now']);
        $entry = $this->createEntry(['id' => 'evasion-phrase']);
        $guard = new WordListGuard;

        $cases = [
            'zero-width space' => "please buy\u{200B} now ok",
            'double space' => 'please buy  now ok',
            'newline' => "please buy\nnow ok",
            'non-breaking space' => "please buy\u{00A0}now ok",
            'uppercase' => 'please BUY NOW ok',
        ];

        foreach ($cases as $label => $text) {
            $this->assertTrue(
                $guard->isSpam($entry, CommentFactory::new()->text($text)->create()),
                "expected the '{$label}' phrase variant to be flagged"
            );
        }

        $this->assertFalse(
            $guard->isSpam($entry, CommentFactory::new()->text('buy some flowers now')->create()),
            'non-contiguous phrase words must not match'
        );
    }

    #[Test]
    public function legitimate_text_is_not_over_matched(): void
    {
        Settings::set('wordlist.banned', ['spam', 'ass']);
        $entry = $this->createEntry(['id' => 'evasion-negative']);
        $guard = new WordListGuard;

        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->text('A perfectly normal comment')->create()));
        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->text('My class starts soon')->create()));
        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->text('A naïve café résumé')->create()));
    }
}
