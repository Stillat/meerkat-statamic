<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Forms;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class FormTagTest extends TestCase
{
    #[Test]
    public function form_tag_emits_the_complete_submission_contract_without_leaking_tag_parameters(): void
    {
        config()->set('meerkat.form.honeypot', 'username');
        $this->createEntry(['id' => 'form-contract']);

        $output = $this->parseAntlers(<<<'ANTLERS'
{{ meerkat:form from_thread="form-contract" redirect="/thanks" meerkat_jump="comment:id" }}
{{ if honeypot }}<input name="{{ honeypot }}" />{{ /if }}
{{ /meerkat:form }}
ANTLERS);

        $contracts = [
            '<form',
            'action="'.route('meerkat.comment-create').'"',
            'method="POST"',
            'name="_token"',
            'name="username"',
            'name="_redirect"',
            'value="/thanks"',
            'name="meerkat_jump" value="comment:id"',
            'name="_meerkat_context" value="form-contract"',
        ];

        foreach ($contracts as $contract) {
            $this->assertStringContainsString($contract, $output);
        }
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*\s(?:from_thread|redirect|meerkat_jump)=/', $output);
    }

    #[Test]
    public function comment_count_defaults_to_public_rows_and_privileged_opt_in_includes_unpublished(): void
    {
        $this->createEntry(['id' => 'count-thread']);
        CommentFactory::new()->threadId('count-thread')->published()->create();
        CommentFactory::new()->threadId('count-thread')->published()->create();
        CommentFactory::new()->threadId('count-thread')->unpublished()->create();

        $this->assertSame('2', trim($this->parseAntlers('{{ meerkat:commentCount thread="count-thread" }}')));

        $this->actingAs($this->userWithPermissions('view comments', 'view blog entries', 'access default site'));
        $this->assertSame('3', trim($this->parseAntlers('{{ meerkat:commentCount thread="count-thread" include_unpublished="true" }}')));
        $this->assertSame('3', trim($this->parseAntlers('{{ meerkat:commentCount thread="count-thread" site="*" include_unpublished="true" }}')));
    }

    #[Test]
    public function replies_to_tag_emits_the_versioned_bundle_route(): void
    {
        $output = trim($this->parseAntlers('{{ meerkat:repliesTo }}'));

        $this->assertStringContainsString('<script', $output);
        $this->assertStringContainsString('/!/meerkat/assets/replies.js?v=', $output);
    }
}
