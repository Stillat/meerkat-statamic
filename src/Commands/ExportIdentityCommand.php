<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Statamic\Console\RunsInPlease;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Services\Identity\IdentityDataResolver;
use Stillat\Meerkat\Services\Identity\IdentityDataset;

class ExportIdentityCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'meerkat:export-identity
        {--email= : Email address to export data for}
        {--user-id= : User id to export data for}
        {--collection= : Restrict the comment scope to one collection}
        {--site= : Restrict the comment scope to one site}
        {--out= : Write JSON to this path; if omitted, prints to stdout}';

    protected $description = 'Export every Meerkat row tied to one identity (by email or user id) as structured JSON.';

    public function handle(IdentityDataResolver $resolver): int
    {
        $email = $this->stringOption('email');
        $userId = $this->stringOption('user-id');

        if (! $email && ! $userId) {
            $this->components->error('Provide --email and/or --user-id.');

            return self::FAILURE;
        }

        $dataset = $resolver->resolve($email, $userId, [
            'collection' => $this->stringOption('collection'),
            'site' => $this->stringOption('site'),
        ]);

        $payload = [
            'subject' => [
                'email' => $dataset->email,
                'user_id' => $dataset->userId,
                'subject_hash' => $dataset->subjectHash(),
            ],
            'generated_at' => now()->toIso8601String(),
            'counts' => $dataset->counts(),
            'comments' => $this->loadComments($dataset),
            'revisions' => $this->loadRevisions($dataset),
            'moderation_actions' => $this->loadModerationActions($dataset),
            'users_meta' => $this->loadUserMeta($dataset),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->components->error('Failed to encode the export: '.json_last_error_msg());

            return self::FAILURE;
        }

        if ($out = $this->stringOption('out')) {
            if (file_put_contents($out, $json) === false) {
                $this->components->error("Failed to write export to {$out}.");

                return self::FAILURE;
            }

            $this->components->info("Wrote export to {$out}.");
        } else {
            $this->line($json);
        }

        Log::info('meerkat.identity.export', [
            'subject_hash' => $dataset->subjectHash(),
            'counts' => $dataset->counts(),
            'operator' => auth()->id(),
            'out' => $out,
        ]);

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadComments(IdentityDataset $dataset): array
    {
        if ($dataset->commentIds === []) {
            return [];
        }

        return array_values(Comment::query()->withTrashed()
            ->whereIn('comments.id', $dataset->commentIds)
            ->get()
            ->map(fn (Comment $c) => $c->toExportArray())
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRevisions(IdentityDataset $dataset): array
    {
        if ($dataset->revisionIds === []) {
            return [];
        }

        return array_values(CommentRevision::query()
            ->whereIn('id', $dataset->revisionIds)
            ->get()
            ->map(fn (CommentRevision $r): array => $this->stringKeyedArray($r->toArray()))
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadModerationActions(IdentityDataset $dataset): array
    {
        if ($dataset->moderationAuditIds === []) {
            return [];
        }

        return array_values(CommentModerationAudit::query()
            ->whereIn('id', $dataset->moderationAuditIds)
            ->get()
            ->map(fn (CommentModerationAudit $m): array => $this->stringKeyedArray($m->toArray()))
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadUserMeta(IdentityDataset $dataset): array
    {
        if ($dataset->userMetaIds === []) {
            return [];
        }

        return array_values(UserMeta::query()->withTrashed()
            ->whereIn('id', $dataset->userMetaIds)
            ->get()
            ->map(fn (UserMeta $u): array => $this->stringKeyedArray($u->toArray()))
            ->values()
            ->all());
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
