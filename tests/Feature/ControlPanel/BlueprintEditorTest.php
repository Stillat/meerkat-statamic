<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\ControlPanel;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Fields\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Stillat\Meerkat\Tests\TestCase;

class BlueprintEditorTest extends TestCase
{
    #[Test]
    public function administrators_can_open_the_active_comment_blueprint_in_statamics_field_editor(): void
    {
        $directory = $this->temporaryDirectory('meerkat-blueprint-editor-');
        $repository = app(BlueprintRepository::class);
        $repository->setDirectory($directory);

        $blueprint = new Blueprint;
        $blueprint->setHandle('meerkat');
        $blueprint->setContents([
            'title' => 'Meerkat Comment',
            'fields' => [
                [
                    'handle' => 'comment',
                    'field' => [
                        'type' => 'textarea',
                        'display' => 'Comment',
                        'validate' => 'required',
                    ],
                ],
            ],
        ]);
        $repository->save($blueprint);

        $this->makeAdmin();

        $response = $this->get(cp_route('meerkat.blueprint.edit'))
            ->assertOk()
            ->assertViewHas('page', function (array $page): bool {
                $props = $page['props'] ?? null;

                if (! is_array($props)) {
                    return false;
                }

                $blueprint = $props['blueprint'] ?? null;

                return is_array($blueprint)
                    && ($page['component'] ?? null) === 'blueprints/Edit'
                    && ($blueprint['handle'] ?? null) === 'meerkat'
                    && ($blueprint['title'] ?? null) === 'Meerkat Comment'
                    && ($props['showTitle'] ?? null) === true;
            });

        $page = $this->requireObject($response->viewData('page'));
        $props = $this->requireObject($page['props'] ?? null);
        $blueprintProps = $this->requireObject($props['blueprint'] ?? null);
        $tabs = $blueprintProps['tabs'] ?? null;

        if (! is_array($tabs)) {
            $this->fail('The blueprint editor response did not contain tabs.');
        }

        $this->patch(cp_route('meerkat.blueprint.update'), [
            'title' => 'Updated Comment Blueprint',
            'hidden' => false,
            'tabs' => $tabs,
        ])->assertOk();

        $this->assertSame('Updated Comment Blueprint', $repository->findOrFail('meerkat')->title());
    }
}
