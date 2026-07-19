<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Statamic\Facades\Scope;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\CP\CpController;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Concerns\GetsMeerkatPermissions;
use Stillat\Meerkat\Support\Features;

class DashboardController extends CpController
{
    use GetsMeerkatConfig,
        GetsMeerkatPermissions;

    public function show(Request $request): View
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('view comments'), 403);

        $site = $request->site ? Site::get($request->site) : Site::selected();

        $blueprint = $this->getBlueprint();
        $fields = $blueprint->fields();

        return view('meerkat::dashboard', [
            'blueprint' => $blueprint->toPublishArray(),
            'site' => $site,
            'columns' => $blueprint->columns()
                ->setPreferred('meerkat.comments.columns')
                ->rejectUnlisted()
                ->values(),
            'filters' => Scope::filters('meerkat.comments', [
                'blueprints' => [$blueprint->handle()],
            ]),
            'meta' => (object) $fields->meta()->all(),
            'permissions' => array_merge(
                $this->getPermissions()->toArray(),
                ['revisions_enabled' => Features::revisions()],
            ),
            'sortColumn' => 'created_at',
            'sortDirection' => 'desc',
        ]);
    }
}
