<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Guard\Guards;

use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;

class WordListGuard extends BaseGuard implements SpamGuard
{
    /** @var array<string, int> */
    protected array $wordList = [];

    public function __construct()
    {
        $words = Settings::get('wordlist.banned', []);

        $this->wordList = collect(is_array($words) ? $words : [])
            ->filter(fn (mixed $word): bool => is_string($word))
            ->map(fn (string $word): string => mb_strtolower(trim($word)))
            ->filter()
            ->unique()
            ->flip()
            ->all();
    }

    /** @return list<string> */
    protected function getWords(string $subject): array
    {
        preg_match_all('/\b\w+\b/u', $subject, $matches);

        return $matches[0];
    }

    public function isSpam(Entry $entry, Comment $comment): bool
    {
        foreach ($this->getCommentSearchSpace($comment) as $subject) {
            if (! is_string($subject)) {
                continue;
            }

            foreach (collect($this->getWords($subject))->map(fn ($word) => mb_strtolower((string) $word)) as $word) {
                if (array_key_exists($word, $this->wordList)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function reportHam(Entry $entry, Comment $comment): void {}

    public function reportSpam(Entry $entry, Comment $comment): void {}
}
