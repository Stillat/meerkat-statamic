<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Services\Identity;

readonly class IdentityDataset
{
    /**
     * @param  list<int>  $commentIds
     * @param  list<int>  $revisionIds
     * @param  list<int>  $moderationAuditIds
     * @param  list<int>  $userMetaIds
     */
    public function __construct(
        public ?string $email,
        public ?string $userId,
        public array $commentIds = [],
        public array $revisionIds = [],
        public array $moderationAuditIds = [],
        public array $userMetaIds = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->commentIds === []
            && $this->revisionIds === []
            && $this->moderationAuditIds === []
            && $this->userMetaIds === [];
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'comments' => count($this->commentIds),
            'revisions' => count($this->revisionIds),
            'moderation_actions' => count($this->moderationAuditIds),
            'users_meta' => count($this->userMetaIds),
        ];
    }

    public function subjectHash(): string
    {
        $material = ($this->email ?? '').'|'.($this->userId ?? '');

        return hash('sha256', $material);
    }
}
