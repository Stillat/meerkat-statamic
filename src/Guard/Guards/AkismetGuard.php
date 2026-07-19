<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Guard\Guards;

use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Concerns\ExtractsFields;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Services\AkismetClient;

class AkismetGuard implements SpamGuard
{
    use ExtractsFields;

    protected AkismetClient $akismet;

    /** @var array<string, mixed> */
    protected array $fieldMapping = [];

    public function __construct()
    {
        $this->akismet = app(AkismetClient::class);
        $mapping = config('meerkat.akismet.fields', []);

        if (is_array($mapping)) {
            foreach ($mapping as $field => $value) {
                if (is_string($field)) {
                    $this->fieldMapping[$field] = $value;
                }
            }
        }
    }

    private function isEnabled(): bool
    {
        return $this->akismet->enabled();
    }

    /** @return array<string, mixed> */
    protected function fillAkismetDetails(Entry $entry, Comment $comment): array
    {

        return [
            'comment_content' => $comment->comment_text,
            'comment_author' => $comment->resolvedName(),
            'comment_author_email' => $comment->resolvedEmail(),
            'comment_type' => Settings::get('akismet.comment_type', 'comment'),
            'referrer' => $comment->referer,
            'user_ip' => $comment->user_ip,
            'user_agent' => $comment->user_agent,
            'permalink' => $entry->permalink ?? '',
        ];
    }

    public function isSpam(Entry $entry, Comment $comment): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return $this->akismet->commentCheck(
            $this->fillAkismetDetails($entry, $comment)
        );
    }

    public function reportHam(Entry $entry, Comment $comment): void
    {
        $this->akismet->submitHam(
            $this->fillAkismetDetails($entry, $comment)
        );
    }

    public function reportSpam(Entry $entry, Comment $comment): void
    {
        $this->akismet->submitSpam(
            $this->fillAkismetDetails($entry, $comment)
        );
    }
}
