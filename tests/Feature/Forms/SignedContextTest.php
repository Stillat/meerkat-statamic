<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Forms;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Support\ContextSigner;
use Stillat\Meerkat\Tests\TestCase;

class SignedContextTest extends TestCase
{
    #[Test]
    public function form_tag_emits_context_and_matching_signature(): void
    {
        $this->createEntry(['id' => 'signed-render']);
        $output = (string) Antlers::parse('{{ meerkat:form thread="signed-render" }}{{ /meerkat:form }}', [], true);

        $this->assertStringContainsString('name="_meerkat_context"', $output);
        $this->assertStringContainsString('name="_meerkat_context_signature"', $output);
        $this->assertStringContainsString('value="'.ContextSigner::sign('signed-render').'"', $output);
    }

    #[Test]
    public function valid_signature_allows_submission(): void
    {
        $this->createEntry(['id' => 'signed-submit']);

        $this->submitComment(['_meerkat_context' => 'signed-submit', 'comment' => 'hello signed'])->assertRedirect();

        $this->assertSame(1, Comment::query()->where('thread_id', 'signed-submit')->count());
    }

    #[Test]
    public function missing_mismatched_and_malformed_signatures_are_silently_indistinguishable(): void
    {
        $this->createEntry(['id' => 'signed-target']);
        $this->createEntry(['id' => 'other-target']);
        $responses = [];
        foreach ([
            null,
            ContextSigner::sign('other-target'),
            str_repeat('a', 64),
        ] as $index => $signature) {
            $payload = [
                '_meerkat_context' => 'signed-target',
                'comment' => 'attempt '.$index,
                'name' => 'Probe',
                'email' => 'probe@example.com',
            ];
            if ($signature !== null) {
                $payload['_meerkat_context_signature'] = $signature;
            }
            $responses[] = $this->post(route('meerkat.comment-create'), $payload);
        }
        $missingContext = $this->post(route('meerkat.comment-create'), [
            '_meerkat_context' => 'does-not-exist',
            '_meerkat_context_signature' => str_repeat('a', 64),
            'comment' => 'probe',
        ]);

        foreach ($responses as $response) {
            $response->assertSessionHasNoErrors();
            $this->assertSame($responses[0]->getStatusCode(), $response->getStatusCode());
        }
        $this->assertSame($responses[0]->getStatusCode(), $missingContext->getStatusCode());
        $this->assertSame(0, Comment::query()->count());
    }

    #[Test]
    public function unsigned_submission_remains_available_when_signature_requirement_is_disabled(): void
    {
        config()->set('meerkat.publishing.require_signed_context', false);
        $this->createEntry(['id' => 'unsigned-allowed']);

        $this->post(route('meerkat.comment-create'), [
            '_meerkat_context' => 'unsigned-allowed',
            'comment' => 'legacy template',
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
        ])->assertRedirect();

        $this->assertSame(1, Comment::query()->where('thread_id', 'unsigned-allowed')->count());
    }
}
