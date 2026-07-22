<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Guard\Guards;

use Normalizer;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;

class WordListGuard extends BaseGuard implements SpamGuard
{
    /** @var array<string, string> */
    private const CONFUSABLES = [
        // Cyrillic look-alikes.
        "\u{0430}" => 'a', "\u{0435}" => 'e', "\u{043E}" => 'o', "\u{0440}" => 'p',
        "\u{0441}" => 'c', "\u{0443}" => 'y', "\u{0445}" => 'x', "\u{0456}" => 'i',
        "\u{0458}" => 'j', "\u{0455}" => 's', "\u{043A}" => 'k', "\u{04BB}" => 'h',
        "\u{0501}" => 'd',
        // Greek look-alikes.
        "\u{03BF}" => 'o', "\u{03B1}" => 'a', "\u{03C1}" => 'p', "\u{03BD}" => 'v',
        "\u{03B9}" => 'i', "\u{03BA}" => 'k', "\u{03C7}" => 'x',
    ];

    /** @var array<string, int> */
    protected array $wordList = [];

    /** @var list<string> */
    protected array $phrases = [];

    public function __construct()
    {
        $words = Settings::get('wordlist.banned', []);

        $entries = collect(is_array($words) ? $words : [])
            ->filter(fn (mixed $word): bool => is_string($word))
            ->map(fn (string $word): string => $this->canonicalize($word))
            ->filter()
            ->unique();

        foreach ($entries as $entry) {
            if (preg_match('/^\w+$/u', $entry) === 1) {
                $this->wordList[$entry] = 1;
            } else {
                $this->phrases[] = $this->collapseWhitespace($entry);
            }
        }
    }

    public function isSpam(Entry $entry, Comment $comment): bool
    {
        foreach ($this->getCommentSearchSpace($comment) as $subject) {
            if (! is_string($subject)) {
                continue;
            }

            $canonical = $this->canonicalize($subject);

            if ($this->matchesPhrase($this->collapseWhitespace($canonical))) {
                return true;
            }

            foreach ($this->getWords($canonical) as $word) {
                if (array_key_exists($word, $this->wordList)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchesPhrase(string $subject): bool
    {
        foreach ($this->phrases as $phrase) {
            if ($phrase !== '' && mb_stripos($subject, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    protected function getWords(string $subject): array
    {
        preg_match_all('/\b\w+\b/u', $subject, $matches);

        return $matches[0];
    }

    private function canonicalize(string $value): string
    {
        $value = preg_replace('/\p{Cf}/u', '', trim($value)) ?? $value;

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KD);

            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;

        return strtr(mb_strtolower($value), self::CONFUSABLES);
    }

    private function collapseWhitespace(string $value): string
    {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $value) ?? $value);
    }

    public function reportHam(Entry $entry, Comment $comment): void {}

    public function reportSpam(Entry $entry, Comment $comment): void {}
}
