<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Guard\Guards;

use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Database\Models\Comment;

class GtubeGuard extends BaseGuard
{
    private const TEST_STRING = 'XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X';

    public function isSpam(Entry $entry, Comment $comment): bool
    {
        foreach ($this->getCommentSearchSpace($comment) as $value) {
            if (! is_string($value)) {
                continue;
            }

            if (Str::contains($value, self::TEST_STRING)) {
                return true;
            }
        }

        return false;
    }

    public function reportHam(Entry $entry, Comment $comment): void {}

    public function reportSpam(Entry $entry, Comment $comment): void {}
}
