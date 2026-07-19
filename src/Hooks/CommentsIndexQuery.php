<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Hooks;

use Statamic\Extensions\Pagination\LengthAwarePaginator;
use Statamic\Hooks\Payload;
use Statamic\Support\Traits\Hookable;
use Stillat\Meerkat\Database\CommentQueryBuilder;

class CommentsIndexQuery
{
    use Hookable;

    public function __construct(private CommentQueryBuilder $query) {}

    public function paginate(?int $perPage): LengthAwarePaginator
    {
        $payload = $this->runHooksWith('query', [
            'query' => $this->query,
        ]);

        if (! $payload instanceof Payload || ! $payload->query instanceof CommentQueryBuilder) {
            throw new \UnexpectedValueException('The comments index query hook must return a comment query builder.');
        }

        return $payload->query->paginate($perPage);
    }
}
