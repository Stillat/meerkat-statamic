<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Api;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Blueprints\CommentBlueprint;
use Stillat\Meerkat\Comments\PublicCommentData;
use Stillat\Meerkat\GraphQL\Types\CommentType;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class FieldClassificationParityTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.graphql.enabled', true);
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('meerkat.graphql.enabled', true);
    }

    #[Test]
    public function every_guarded_key_is_covered_by_the_privileged_api_fields(): void
    {
        $comment = CommentFactory::new()->threadId('parity')->published()->create();

        $covered = array_merge(
            array_keys(PublicCommentData::privilegedFields($comment)),
            ['email', 'author_email'],
        );

        $this->assertSame([], array_values(array_diff(PublicCommentData::GUARDED_KEYS, $covered)));
    }

    #[Test]
    public function graphql_explicit_fields_never_expose_a_guarded_key(): void
    {
        $type = new CommentType;
        $method = new \ReflectionMethod($type, 'explicitFields');

        $fields = $method->invoke($type);
        $this->assertInstanceOf(Collection::class, $fields);
        $keys = array_keys($fields->all());

        $this->assertNotEmpty($keys);
        $this->assertSame([], array_values(array_intersect($keys, PublicCommentData::GUARDED_KEYS)));
    }

    #[Test]
    public function guarded_blueprint_handles_are_excluded_from_graphql_passthrough(): void
    {
        $handles = array_keys(CommentBlueprint::getBlueprint('meerkat')->fields()->all()->all());
        $handled = (new \ReflectionClassConstant(CommentType::class, 'HANDLED_EXPLICITLY'))->getValue();
        $this->assertIsArray($handled);
        $handled = array_values(array_filter($handled, is_string(...)));

        $guardedBlueprintHandles = array_values(array_intersect($handles, PublicCommentData::GUARDED_KEYS));

        $this->assertSame([], array_values(array_diff($guardedBlueprintHandles, $handled)));
    }
}
