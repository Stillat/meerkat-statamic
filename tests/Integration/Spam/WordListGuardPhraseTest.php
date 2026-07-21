<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Spam;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Guard\Guards\WordListGuard;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class WordListGuardPhraseTest extends TestCase
{
    #[Test]
    public function multi_word_phrases_match_as_case_insensitive_substrings(): void
    {
        Settings::set('wordlist.banned', ['cheap pills']);
        $entry = $this->createEntry(['id' => 'phrase-match']);
        $guard = new WordListGuard;

        $this->assertTrue($guard->isSpam($entry, CommentFactory::new()->text('Get CHEAP Pills today')->create()));
        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->text('Cheap flights and vitamin pills')->create()));
    }

    #[Test]
    public function domain_entries_match_inside_urls(): void
    {
        Settings::set('wordlist.banned', ['spam.example.com']);
        $entry = $this->createEntry(['id' => 'domain-match']);
        $guard = new WordListGuard;

        $this->assertTrue($guard->isSpam($entry, CommentFactory::new()->text('Visit https://spam.example.com/deal now')->create()));
        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->text('Visit https://example.com/deal now')->create()));
    }

    #[Test]
    public function single_word_entries_keep_word_boundary_semantics(): void
    {
        Settings::set('wordlist.banned', ['ass']);
        $entry = $this->createEntry(['id' => 'boundary-match']);
        $guard = new WordListGuard;

        $this->assertFalse($guard->isSpam($entry, CommentFactory::new()->text('My class starts soon')->create()));
        $this->assertTrue($guard->isSpam($entry, CommentFactory::new()->text('You ass!')->create()));
    }
}
