<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Permissions;

readonly class Permissions
{
    /**
     * @param  list<string>  $accessibleSites
     * @param  list<string>  $accessibleCollections
     */
    public function __construct(
        public bool $canViewComments = false,
        public bool $canEditComments = false,
        public bool $canDeleteComments = false,
        public bool $canCheckCommentSpam = false,
        public bool $canReportCommentSpam = false,
        public bool $canSubmitComments = false,
        public bool $hasSiteRestrictions = true,
        public bool $hasCollectionRestrictions = true,
        public array $accessibleSites = [],
        public array $accessibleCollections = [],
    ) {}

    public function canAccessSite(string $handle): bool
    {
        if (! $this->hasSiteRestrictions) {
            return true;
        }

        return in_array($handle, $this->accessibleSites, true);
    }

    public function canAccessCollection(string $handle): bool
    {
        if (! $this->hasCollectionRestrictions) {
            return true;
        }

        return in_array($handle, $this->accessibleCollections, true);
    }

    /** @return array<string, bool|list<string>> */
    public function toArray(): array
    {
        return [
            'can_view_comments' => $this->canViewComments,
            'can_edit_comments' => $this->canEditComments,
            'can_delete_comments' => $this->canDeleteComments,
            'can_check_comment_spam' => $this->canCheckCommentSpam,
            'can_report_comment_spam' => $this->canReportCommentSpam,
            'can_submit_comments' => $this->canSubmitComments,
            'has_site_restrictions' => $this->hasSiteRestrictions,
            'has_collection_restrictions' => $this->hasCollectionRestrictions,
            'accessible_sites' => $this->accessibleSites,
            'accessible_collections' => $this->accessibleCollections,
        ];
    }
}
