<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Concerns;

use Illuminate\Support\Collection;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection as CollectionApi;
use Statamic\Facades\Site as SiteApi;
use Statamic\Sites\Site;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Permissions\Permissions;

trait GetsMeerkatPermissions
{
    protected function getPermissions(): Permissions
    {
        $user = auth()->user();
        $cacheKey = 'meerkat.user.permissions.'.(auth()->id() ?? 'guest');

        $permissions = Blink::once($cacheKey, function () use ($user) {
            if (! $user) {
                return new Permissions;
            }

            $allCollections = CollectionApi::all();
            $visibleCollections = $allCollections
                ->filter(fn ($collection) => $user->can('view', $collection))
                ->pluck('handle')
                ->filter(fn (mixed $handle): bool => is_string($handle))
                ->values()
                ->all();
            $hasCollectionRestrictions = $allCollections->count() != count($visibleCollections);

            $allSitesValue = SiteApi::all();
            $allSites = $allSitesValue instanceof Collection
                ? $allSitesValue
                : collect();
            $visibleSites = $allSites
                ->filter(fn (mixed $site): bool => $site instanceof Site && $user->can('view', $site))
                ->map(fn (Site $site): string => $site->handle())
                ->filter(fn (string $handle): bool => $handle !== '')
                ->values()
                ->all();

            $hasSiteRestrictions = $allSites->count() !== count($visibleSites);

            return new Permissions(
                canViewComments: $user->can('view comments'),
                canEditComments: $user->can('edit comments'),
                canDeleteComments: $user->can('delete comments'),
                canCheckCommentSpam: $user->can('check comment spam'),
                canReportCommentSpam: $user->can('report comment spam'),
                canSubmitComments: $user->can('submit comments'),
                hasSiteRestrictions: $hasSiteRestrictions,
                hasCollectionRestrictions: $hasCollectionRestrictions,
                accessibleSites: array_values($visibleSites),
                accessibleCollections: array_values($visibleCollections)
            );
        });

        return $permissions instanceof Permissions ? $permissions : new Permissions;
    }

    protected function assertCanAccessComment(Comment $comment): void
    {
        $permissions = $this->getPermissions();

        abort_unless($permissions->canAccessCollection($comment->collection), 403);
        abort_unless($permissions->canAccessSite($comment->site), 403);
    }
}
