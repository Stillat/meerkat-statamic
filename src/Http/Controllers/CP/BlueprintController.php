<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Controllers\CP;

use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Http\Request;
use Inertia\Response;
use LogicException;
use Statamic\CP\Breadcrumbs\Breadcrumb;
use Statamic\CP\Breadcrumbs\Breadcrumbs;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Controllers\CP\Fields\ManagesBlueprints;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;

class BlueprintController extends CpController
{
    use GetsMeerkatConfig;
    use ManagesBlueprints;

    public function __construct()
    {
        $this->middleware(Authorize::class.':configure fields');
    }

    public function edit(): Response
    {
        $blueprint = $this->getBlueprint();
        $title = $blueprint->title();

        if (! is_string($title) || $title === '') {
            $handle = $blueprint->handle();
            $title = is_string($handle) && $handle !== '' ? $handle : 'Meerkat Comment';
        }

        Breadcrumbs::push(new Breadcrumb(
            text: __('meerkat::general.dashboard_title'),
            url: cp_route('meerkat.cp.dashboard'),
            icon: 'mail-chat-bubble-text',
        ));

        Breadcrumbs::push(new Breadcrumb(
            text: $title,
            icon: 'blueprints',
        ));

        $response = $this->renderEditPage([
            'blueprint' => $this->toVueObject($blueprint),
            'action' => cp_route('meerkat.blueprint.update'),
            'showTitle' => true,
        ]);

        if (! $response instanceof Response) {
            throw new LogicException('Statamic did not return a blueprint editor response.');
        }

        return $response;
    }

    public function update(Request $request): void
    {
        $request->validate([
            'title' => 'required',
            'tabs' => 'array',
        ]);

        $this->updateBlueprint($request, $this->getBlueprint());
    }
}
