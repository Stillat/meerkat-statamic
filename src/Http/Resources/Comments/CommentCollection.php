<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Resources\Comments;

use Stillat\Meerkat\Http\Resources\ListedResourceCollection;

class CommentCollection extends ListedResourceCollection
{
    public $collects = CommentResource::class;
}
