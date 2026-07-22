<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Guard\Guards;

use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;

class IpFilterGuard implements SpamGuard
{
    /** @var list<string> */
    protected array $blockedIps = [];

    public function __construct()
    {
        $blockedIps = Settings::get('iplist.block', []);

        $this->blockedIps = array_values(collect(is_array($blockedIps) ? $blockedIps : [])
            ->filter(fn (mixed $address): bool => is_string($address))
            ->filter()
            ->unique()
            ->values()
            ->all());
    }

    public function isSpam(Entry $entry, Comment $comment): bool
    {
        $address = $comment->user_ip;

        return is_string($address) && in_array($address, $this->blockedIps, true);
    }

    public function reportHam(Entry $entry, Comment $comment): void {}

    public function reportSpam(Entry $entry, Comment $comment): void {}
}
