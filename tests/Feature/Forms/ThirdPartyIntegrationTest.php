<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Forms;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Forms\CommentSubmission;
use Stillat\Meerkat\Forms\StatamicFormBridge;
use Stillat\Meerkat\Http\Controllers\CommentController;
use Stillat\Meerkat\Tests\TestCase;

class ThirdPartyIntegrationTest extends TestCase
{
    #[Test]
    public function submission_route_uses_the_registered_meerkat_middleware_group(): void
    {
        $router = app(Router::class);
        $route = collect(Route::getRoutes()->getRoutes())
            ->firstWhere(fn (\Illuminate\Routing\Route $route): bool => $route->getName() === 'meerkat.comment-create');

        $this->assertArrayHasKey('meerkat-form-submit', $router->getMiddlewareGroups());
        $this->assertNotNull($route);
        $this->assertContains('meerkat-form-submit', $route->gatherMiddleware());
    }

    #[Test]
    public function form_events_receive_a_sanitized_non_persisting_submission_contract(): void
    {
        $this->createEntry(['id' => 'third-party-contract']);
        $submitted = null;
        $created = null;

        Event::listen(FormSubmitted::class, function (FormSubmitted $event) use (&$submitted) {
            $submitted = $event->submission;
        });
        Event::listen(SubmissionCreated::class, function (SubmissionCreated $event) use (&$created) {
            $created = $event->submission;
        });

        $this->submitComment([
            '_meerkat_context' => 'third-party-contract',
            'comment' => 'body content',
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'cf-turnstile-response' => 'XXXX.DUMMY.TURNSTILE.TOKEN',
        ]);

        $this->assertInstanceOf(Submission::class, $submitted);
        $this->assertInstanceOf(CommentSubmission::class, $submitted);
        $this->assertSame(StatamicFormBridge::FORM_HANDLE, $submitted->form()->handle());
        $this->assertSame('body content', $submitted->data()->get('comment'));
        $this->assertSame('Alice', $submitted->data()->get('name'));
        $this->assertSame('alice@example.com', $submitted->data()->get('email'));
        $this->assertSame('XXXX.DUMMY.TURNSTILE.TOKEN', $submitted->data()->get('cf-turnstile-response'));

        foreach (['_meerkat_context', '_meerkat_context_signature', '_token', '_redirect', '_error_redirect'] as $internal) {
            $this->assertFalse($submitted->data()->has($internal));
        }

        $submitted->save();
        $submitted->saveQuietly();
        $submitted->delete();
        $submitted->deleteQuietly();
        $this->assertSame(0, $submitted->form()->submissions()->count());

        $this->assertInstanceOf(CommentSubmission::class, $created);
        $this->assertSame('body content', $created->data()->get('comment'));

        $saved = Comment::query()->where('comments.thread_id', 'third-party-contract')->firstOrFail();
        $this->assertArrayNotHasKey('cf-turnstile-response', (array) $saved->comment_data);
    }

    #[Test]
    public function a_listener_can_silently_halt_submission_before_persistence_and_creation_events(): void
    {
        $this->createEntry(['id' => 'third-party-halt']);
        $created = false;
        Event::listen(FormSubmitted::class, fn () => false);
        Event::listen(SubmissionCreated::class, function () use (&$created) {
            $created = true;
        });
        $countBefore = Comment::query()->count();

        $response = $this->submitComment([
            '_meerkat_context' => 'third-party-halt',
            'comment' => 'should be dropped',
            'name' => 'Bot',
            'email' => 'bot@example.com',
        ]);

        $response->assertRedirect();
        $this->assertSame($countBefore, Comment::query()->count());
        $this->assertFalse($created);
    }

    #[Test]
    public function listener_validation_exceptions_surface_in_the_meerkat_error_bag(): void
    {
        $this->createEntry(['id' => 'third-party-validation']);
        Event::listen(FormSubmitted::class, function () {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => 'Captcha verification failed.',
            ]);
        });
        $countBefore = Comment::query()->count();

        $response = $this->submitComment([
            '_meerkat_context' => 'third-party-validation',
            'comment' => 'should be rejected',
            'name' => 'Bot',
            'email' => 'bot@example.com',
        ]);

        $response->assertSessionHasErrorsIn('meerkat', ['cf-turnstile-response']);
        $this->assertSame($countBefore, Comment::query()->count());
    }

    #[Test]
    public function listener_mutations_map_to_model_fields_and_custom_comment_data(): void
    {
        $this->createEntry(['id' => 'third-party-mutations']);
        Event::listen(FormSubmitted::class, function (FormSubmitted $event) {
            $event->submission->set('comment', 'NORMALIZED BODY');
            $event->submission->set('name', 'Normalized Author');
            $event->submission->set('email', 'normalized@example.com');
            $event->submission->set('verified_by', 'turnstile');
            $event->submission->set('verified_at', '2026-01-01T00:00:00Z');
        });

        $this->submitComment([
            '_meerkat_context' => 'third-party-mutations',
            'comment' => 'original body',
            'name' => 'Raw Author',
            'email' => 'raw@example.com',
        ]);

        $saved = Comment::query()->where('comments.thread_id', 'third-party-mutations')->firstOrFail();
        $this->assertSame('NORMALIZED BODY', $saved->comment_text);
        $this->assertSame('Normalized Author', $saved->author_name);
        $this->assertSame('normalized@example.com', $saved->author_email);
        $this->assertSame('turnstile', $saved->comment_data['verified_by'] ?? null);
        $this->assertSame('2026-01-01T00:00:00Z', $saved->comment_data['verified_at'] ?? null);
    }

    #[Test]
    public function before_saving_hook_takes_precedence_over_form_listener_mutations(): void
    {
        $this->createEntry(['id' => 'third-party-precedence']);
        Event::listen(FormSubmitted::class, function (FormSubmitted $event) {
            $event->submission->set('comment', 'from listener');
        });
        CommentController::hook('before-saving-comment', function (mixed $payload) {
            if (! $payload instanceof Payload || ! $payload->comment instanceof Comment) {
                throw new LogicException('The before-saving hook did not receive a comment payload.');
            }

            $payload->comment->comment_text = 'from hook';

            return $payload;
        });

        $this->submitComment([
            '_meerkat_context' => 'third-party-precedence',
            'comment' => 'original',
            'name' => 'Author',
            'email' => 'author@example.com',
        ]);

        $saved = Comment::query()->where('comments.thread_id', 'third-party-precedence')->firstOrFail();
        $this->assertSame('from hook', $saved->comment_text);
    }
}
