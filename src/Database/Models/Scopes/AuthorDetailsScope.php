<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;

class AuthorDetailsScope implements Scope
{
    use GetsMeerkatConfig;

    public function apply(Builder $builder, Model $model)
    {
        $prefix = $model->getConnection()->getTablePrefix();
        $comments = $prefix.'comments';
        $usersMeta = $prefix.'users_meta';

        $builder
            ->leftJoin('users_meta', 'users_meta.user_id', '=', 'comments.author_id')
            ->select('comments.*')
            ->selectRaw("COALESCE({$comments}.author_name, {$usersMeta}.name, ?) as name", [
                $this->getDefaultAuthorName(),
            ])
            ->selectRaw("COALESCE({$comments}.author_email, {$usersMeta}.email, ?) as email", [
                $this->getDefaultAuthorEmail(),
            ]);
    }
}
