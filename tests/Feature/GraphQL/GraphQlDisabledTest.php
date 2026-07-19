<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\GraphQL;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class GraphQlDisabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.graphql.enabled', true);
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('meerkat.graphql.enabled', false);
    }

    #[Test]
    public function meerkat_queries_are_not_registered_when_disabled(): void
    {
        $response = $this->postJson('/graphql', [
            'query' => '{ meerkatComments(thread_id: "x") { total } }',
        ]);

        $errors = json_encode($response->json('errors'), JSON_THROW_ON_ERROR);

        $this->assertNotNull($response->json('errors'));
        $this->assertStringContainsString('meerkatComments', $errors);
        $this->assertNull($response->json('data.meerkatComments'));
    }
}
