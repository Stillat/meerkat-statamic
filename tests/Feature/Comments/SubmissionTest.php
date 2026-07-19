<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Comments;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Events\CommentSaved;
use Stillat\Meerkat\Events\CommentSubmitted;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Support\ContextSigner;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class SubmissionTest extends TestCase
{
    #[Test]
    public function guest_submission_creates_the_thread_and_normalized_root_comment(): void
    {
        $this->createEntry(['id' => 'guest-root']);

        $this->submitComment([
            '_meerkat_context' => 'guest-root',
            'comment' => 'This is my test comment.',
            'name' => 'John Doe',
            'email' => '  JOHN@EXAMPLE.COM  ',
        ])->assertRedirect();

        $comment = Comment::query()->where('thread_id', 'guest-root')->firstOrFail();
        $this->assertSame('This is my test comment.', $comment->comment_text);
        $this->assertSame('John Doe', $comment->author_name);
        $this->assertSame('john@example.com', $comment->author_email);
        $this->assertSame(0, $comment->depth);
        $this->assertNull($comment->parent_id);
        $this->assertDatabaseHas('threads', ['thread_id' => 'guest-root'], 'meerkat');
    }

    #[Test]
    public function required_comment_validation_prevents_persistence(): void
    {
        $this->createEntry(['id' => 'required-comment']);

        $this->submitComment([
            '_meerkat_context' => 'required-comment',
            'comment' => '',
        ])->assertSessionHasErrors();

        $this->assertSame(0, Comment::query()->where('thread_id', 'required-comment')->count());
    }

    #[Test]
    public function guest_email_policy_distinguishes_missing_optional_and_invalid_values(): void
    {
        $this->useEmailRequiredBlueprint();
        foreach (['email-required', 'email-optional', 'email-invalid'] as $entry) {
            $this->createEntry(['id' => $entry]);
        }

        $this->submitComment([
            '_meerkat_context' => 'email-required',
            'comment' => 'Missing email',
            'name' => 'Guest',
            'email' => null,
        ])->assertSessionHasErrors(['email'], null, 'meerkat');

        config()->set('meerkat.publishing.require_guest_email', false);
        $this->submitComment([
            '_meerkat_context' => 'email-optional',
            'comment' => 'Optional email',
            'name' => 'Guest',
            'email' => null,
        ])->assertRedirect();
        $this->assertNull(Comment::query()->where('thread_id', 'email-optional')->firstOrFail()->author_email);

        $this->submitComment([
            '_meerkat_context' => 'email-invalid',
            'comment' => 'Invalid email',
            'name' => 'Guest',
            'email' => 'not-an-email',
        ])->assertSessionHasErrors(['email'], null, 'meerkat');
        $this->assertSame(0, Comment::query()->where('thread_id', 'email-invalid')->count());
    }

    #[Test]
    public function submissions_require_a_resolvable_signed_context(): void
    {
        $this->submitComment([
            '_meerkat_context' => 'non-existent-entry',
            'comment' => 'This should fail.',
        ])->assertSessionHasErrors(
            ['_meerkat_context' => __('meerkat::validation.thread_exists')],
            null,
            'meerkat',
        );

        $this->assertSame(0, Comment::count());
    }

    #[Test]
    public function nested_submissions_materialize_ordered_paths_parent_ids_and_arbitrary_depth(): void
    {
        CommentFactory::resetCounter();
        $this->createEntry(['id' => 'nested-submissions']);

        foreach (['First root', 'Second root'] as $body) {
            $this->submitComment([
                '_meerkat_context' => 'nested-submissions',
                'comment' => $body,
                'name' => 'Guest',
            ])->assertRedirect();
        }
        $roots = Comment::query()->where('thread_id', 'nested-submissions')->orderBy('id')->get();
        $this->assertSame(['1', '2'], $roots->pluck('path')->all());

        $level0 = CommentFactory::new()->threadId('nested-submissions')->text('Level 0')->depth(0)->published()->create();
        $level1 = CommentFactory::new()->threadId('nested-submissions')->text('Level 1')->parent($level0->id)->depth(1)->published()->create();
        $level2 = CommentFactory::new()->threadId('nested-submissions')->text('Level 2')->parent($level1->id)->depth(2)->published()->create();

        $this->submitComment([
            '_meerkat_context' => 'nested-submissions',
            'comment' => 'Level 3',
            'name' => 'Replier',
            'ids' => (string) $level2->id,
        ])->assertRedirect();

        $reply = Comment::query()->where('comment_text', 'Level 3')->firstOrFail();
        $this->assertSame($level2->id, $reply->parent_id);
        $this->assertSame(3, $reply->depth);
    }

    #[Test]
    public function replies_reject_missing_and_publicly_hidden_parents(): void
    {
        $this->createEntry(['id' => 'invalid-parents']);
        $missing = $this->submitComment([
            '_meerkat_context' => 'invalid-parents',
            'comment' => 'Missing parent reply',
            'ids' => '99999',
        ]);
        $missing->assertSessionHasErrors(['ids' => __('meerkat::validation.parent_exists')], null, 'meerkat');

        $unpublished = CommentFactory::new()->threadId('invalid-parents')->text('unpublished')->unpublished()->create();
        $spam = CommentFactory::new()->threadId('invalid-parents')->text('spam')->published()->spam()->create();
        $removed = CommentFactory::new()->threadId('invalid-parents')->text('removed')->published()->create();
        Comments::deleteComment($removed->id);

        foreach ([$unpublished, $spam, $removed] as $parent) {
            $body = 'Hidden parent reply '.$parent->id;
            $this->submitComment([
                '_meerkat_context' => 'invalid-parents',
                'comment' => $body,
                'ids' => (string) $parent->id,
            ])->assertSessionHasErrors(['ids' => __('meerkat::validation.parent_visible')], null, 'meerkat');
            $this->assertNull(Comment::query()->where('comment_text', $body)->first());
        }
    }

    #[Test]
    public function successful_submission_dispatches_saved_and_submitted_events(): void
    {
        Event::fake([CommentSaved::class, CommentSubmitted::class]);
        $this->createEntry(['id' => 'submission-events']);

        $this->submitComment([
            '_meerkat_context' => 'submission-events',
            'comment' => 'Event test comment',
        ]);

        Event::assertDispatchedTimes(CommentSaved::class, 1);
        Event::assertDispatched(CommentSubmitted::class);
    }

    #[Test]
    public function honeypot_submission_is_silently_discarded(): void
    {
        $this->createEntry(['id' => 'honeypot']);

        $this->submitComment([
            '_meerkat_context' => 'honeypot',
            'comment' => 'This is spam',
            'username' => 'spam-bot',
        ])->assertRedirect();

        $this->assertSame(0, Comment::query()->where('thread_id', 'honeypot')->count());
    }

    #[Test]
    public function ajax_submission_returns_success_and_persists_the_comment(): void
    {
        $this->createEntry(['id' => 'ajax-submission']);

        $this->postJson(route('meerkat.comment-create'), [
            '_meerkat_context' => 'ajax-submission',
            '_meerkat_context_signature' => ContextSigner::sign('ajax-submission'),
            'comment' => 'AJAX comment',
            'name' => 'AJAX User',
            'email' => 'ajax@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('comments', [
            'thread_id' => 'ajax-submission',
            'comment_text' => 'AJAX comment',
        ], 'meerkat');
    }

    #[Test]
    public function redirects_reject_external_destinations_and_only_accept_safe_fragment_jumps(): void
    {
        foreach (['redirect-success', 'redirect-error', 'redirect-jump'] as $entry) {
            $this->createEntry(['id' => $entry]);
        }

        $success = $this->from('/safe-origin')->submitComment([
            '_meerkat_context' => 'redirect-success',
            '_redirect' => 'https://attacker.example/phish',
            'comment' => 'Redirect check',
        ]);
        $this->assertStringContainsString('/safe-origin#comments', (string) $success->headers->get('Location'));

        $error = $this->from('/safe-error-origin')->submitComment([
            '_meerkat_context' => 'redirect-error',
            '_error_redirect' => 'https://attacker.example/error',
            'comment' => '',
        ]);
        $this->assertStringContainsString('/safe-error-origin#comments', (string) $error->headers->get('Location'));

        $unsafe = $this->submitComment([
            '_meerkat_context' => 'redirect-jump',
            '_redirect' => '/thanks',
            'meerkat_jump' => 'to:https://attacker.example/path',
            'comment' => 'Unsafe jump',
        ]);
        $this->assertStringEndsWith('/thanks#comments', (string) $unsafe->headers->get('Location'));

        $safe = $this->submitComment([
            '_meerkat_context' => 'redirect-jump',
            '_redirect' => '/thanks',
            'meerkat_jump' => 'to:#custom-comments',
            'comment' => 'Safe jump',
        ]);
        $this->assertStringEndsWith('/thanks#custom-comments', (string) $safe->headers->get('Location'));
    }

    #[Test]
    public function authenticated_identity_is_authoritative_even_when_guest_fields_are_missing_or_forged(): void
    {
        $this->createEntry(['id' => 'authenticated-submissions']);
        $user = $this->makeStatamicUser();
        $user->id('identity-user');
        $user->email('real@example.com');
        $user->data(['name' => 'Real Name']);
        $user->makeSuper();
        $user->save();
        $this->actingAs($user);

        $this->submitComment([
            '_meerkat_context' => 'authenticated-submissions',
            'comment' => 'No guest fields',
        ])->assertRedirect();
        $this->submitComment([
            '_meerkat_context' => 'authenticated-submissions',
            'comment' => 'Forged guest fields',
            'name' => 'Fake Name',
            'email' => 'fake@example.com',
        ])->assertRedirect();

        $comments = Comment::query()->where('thread_id', 'authenticated-submissions')->orderBy('id')->get();
        $this->assertCount(2, $comments);
        foreach ($comments as $comment) {
            $this->assertSame('identity-user', $comment->author_id);
            $this->assertSame('Real Name', $comment->resolvedName());
            $this->assertSame('real@example.com', $comment->resolvedEmail());
            $this->assertArrayNotHasKey('name', $comment->comment_data);
            $this->assertArrayNotHasKey('email', $comment->comment_data);
        }
    }

    private function useEmailRequiredBlueprint(): void
    {
        app(BlueprintRepository::class)->setFallback('email_required_meerkat', fn () => Blueprint::makeFromFields([
            'comment' => ['type' => 'textarea', 'display' => 'Comment', 'validate' => 'required'],
            'name' => ['type' => 'text', 'display' => 'Name', 'validate' => 'required'],
            'email' => [
                'type' => 'text',
                'input_type' => 'email',
                'display' => 'Email',
                'validate' => 'required|email',
            ],
        ]));
        config()->set('meerkat.form.blueprint', 'email_required_meerkat');
    }
}
