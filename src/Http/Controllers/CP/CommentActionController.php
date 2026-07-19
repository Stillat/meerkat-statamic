<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Controllers\CP;

use Illuminate\Database\Eloquent\Collection;
use Statamic\Http\Controllers\CP\ActionController;
use Stillat\Meerkat\Concerns\GetsMeerkatPermissions;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Support\CommentVisibility;

class CommentActionController extends ActionController
{
    use GetsMeerkatPermissions;

    /**
     * @param  array<int, int|string>  $items
     * @param  array<string, mixed>  $context
     * @return Collection<int, Comment>
     */
    protected function getSelectedItems($items, $context): Collection
    {
        $query = Comments::query()->whereIn('comments.id', $items);

        app(CommentVisibility::class)->applyAccessibleScope($query, $this->getPermissions());

        return $query->get();
    }
}
