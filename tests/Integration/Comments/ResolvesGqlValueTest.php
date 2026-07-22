<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ResolvesGqlValueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(BlueprintRepository::class)->setFallback('meerkat_unit', fn () => Blueprint::makeFromFields([
            'comment' => ['type' => 'textarea', 'display' => 'Comment'],
            'rating' => ['type' => 'integer', 'display' => 'Rating'],
        ]));

        config()->set('meerkat.form.blueprint', 'meerkat_unit');
    }

    #[Test]
    public function it_resolves_blueprint_and_custom_data_fields_through_augmentation(): void
    {
        $comment = CommentFactory::new()
            ->threadId('gql-unit')
            ->text('Body text')
            ->data(['comment' => 'Body text', 'rating' => 7])
            ->published()
            ->create();

        $this->assertSame('Body text', $comment->resolveGqlValue('comment'));
        $this->assertSame(7, $comment->resolveGqlValue('rating'));
    }
}
