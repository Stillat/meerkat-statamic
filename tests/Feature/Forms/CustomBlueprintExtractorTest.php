<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Forms;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\UserSaved;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Listeners\UserSavedListener;
use Stillat\Meerkat\Support\ContextSigner;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\Fixtures\CustomAuthorExtractor;
use Stillat\Meerkat\Tests\Fixtures\CustomCommentExtractor;
use Stillat\Meerkat\Tests\Fixtures\MissingCommentExtractor;
use Stillat\Meerkat\Tests\TestCase;

class CustomBlueprintExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerCustomBlueprint();

        config()->set('meerkat.form.blueprint', 'custom_meerkat');
        config()->set('meerkat.fields.extractors.comment', CustomCommentExtractor::class);
        config()->set('meerkat.fields.extractors.author', CustomAuthorExtractor::class);
    }

    #[Test]
    public function custom_extractors_store_canonical_comment_and_author_values(): void
    {
        $this->createEntry(['id' => 'custom-submit']);

        $this->submitComment([
            '_meerkat_context' => 'custom-submit',
            'body' => 'Submitted through a custom body field.',
            'display_name' => 'Custom Author',
            'contact_email' => 'CUSTOM@EXAMPLE.COM',
            'mood' => 'curious',
        ])->assertStatus(302);

        $comment = Comment::query()->where('thread_id', 'custom-submit')->first();

        $this->assertNotNull($comment);
        $this->assertSame('Submitted through a custom body field.', $comment->comment_text);
        $this->assertSame('Custom Author', $comment->author_name);
        $this->assertSame('custom@example.com', $comment->author_email);
        $this->assertSame('curious', $comment->comment_data['mood']);
    }

    #[Test]
    public function missing_required_extractor_output_returns_a_validation_error(): void
    {
        config()->set('meerkat.fields.extractors.comment', MissingCommentExtractor::class);

        $this->createEntry(['id' => 'bad-extractor']);

        $this->submitComment([
            '_meerkat_context' => 'bad-extractor',
            'body' => 'This should not save.',
            'display_name' => 'Custom Author',
            'contact_email' => 'custom@example.com',
        ])->assertSessionHasErrors('comment', null, 'meerkat');

        $this->assertSame(0, Comment::query()->where('thread_id', 'bad-extractor')->count());
    }

    #[Test]
    public function cp_listing_returns_custom_blueprint_fields_from_comment_data(): void
    {
        $collection = $this->makeStatamicCollection('blog');
        $collection->title('Blog');
        $collection->save();

        $user = $this->makeStatamicUser();
        $user->id('custom-cp-admin');
        $user->email('custom-cp-admin@example.com');
        $user->makeSuper();
        $user->save();
        $this->actingAs($user);

        CommentFactory::new()
            ->threadId('custom-listing')
            ->author('Listing Author', 'listing@example.com')
            ->text('Canonical listing body')
            ->data([
                'body' => 'Custom listing body',
                'display_name' => 'Listing Author',
                'contact_email' => 'listing@example.com',
                'mood' => 'focused',
            ])
            ->published()
            ->create();

        $rows = $this->requireRows($this->getJson(cp_route('meerkat.cp.comments.index').'?columns=body,display_name,contact_email,mood')
            ->assertOk()
            ->json('data'));

        $row = collect($rows)->firstWhere('comment_text', 'Canonical listing body');

        $this->assertNotNull($row);
        $row = $this->requireObject($row);
        $this->assertSame('Custom listing body', $row['body']);
        $this->assertSame('Listing Author', $row['display_name']);
        $this->assertSame('listing@example.com', $row['contact_email']);
        $this->assertSame('focused', $row['mood']);
    }

    #[Test]
    public function public_comment_listing_keeps_canonical_and_custom_values_available(): void
    {
        $this->createEntry(['id' => 'custom-render']);

        CommentFactory::new()
            ->threadId('custom-render')
            ->author('Render Author', 'render@example.com')
            ->text('Canonical render body')
            ->data([
                'body' => 'Custom render body',
                'display_name' => 'Render Author',
                'contact_email' => 'render@example.com',
            ])
            ->published()
            ->create();

        $apiRow = $this->requireObject($this->getJson('/api/meerkat/threads/custom-render/comments')
            ->assertOk()
            ->json('comments.0'));

        $this->assertSame('Canonical render body', $apiRow['comment_text']);

        $rendered = (string) Antlers::parse(<<<'ANTLERS'
{{ meerkat:comments thread="custom-render" }}
{{ body }}|{{ comment_text }}
{{ /meerkat:comments }}
ANTLERS, [], true);

        $this->assertStringContainsString('Custom render body|', $rendered);
    }

    #[Test]
    public function authenticated_submission_with_custom_author_extractor_uses_statamic_identity(): void
    {
        $this->registerAuthenticatedOnlyBlueprint();
        config()->set('meerkat.form.blueprint', 'auth_custom_meerkat');

        $this->createEntry(['id' => 'custom-auth-submit']);

        $user = $this->userWithPermissions('submit comments');
        $this->actingAs($user);

        $this->post(route('meerkat.comment-create'), [
            '_meerkat_context' => 'custom-auth-submit',
            '_meerkat_context_signature' => ContextSigner::sign('custom-auth-submit'),
            'body' => 'Authenticated custom body.',
            'mood' => 'steady',
        ])->assertStatus(302);

        $comment = Comment::query()->where('thread_id', 'custom-auth-submit')->first();

        $this->assertNotNull($comment);
        $this->assertSame('Authenticated custom body.', $comment->comment_text);
        $this->assertSame($user->id(), $comment->author_id);
        $this->assertNull($comment->author_name);
        $this->assertNull($comment->author_email);

        $meta = UserMeta::query()->where('user_id', $user->id())->first();

        $this->assertNotNull($meta);
        $this->assertSame($user->email(), $meta->name);
        $this->assertSame($user->email(), $meta->email);
    }

    #[Test]
    public function user_meta_sync_uses_statamic_identity_not_form_field_extractors(): void
    {
        $user = $this->makeStatamicUser();
        $user->id('custom-user-meta');
        $user->email('CUSTOM-USER-META@EXAMPLE.COM');
        $user->data(['name' => 'Custom User Meta']);
        $user->save();

        (new UserSavedListener)->handle(new UserSaved($user));

        $meta = UserMeta::query()->where('user_id', 'custom-user-meta')->first();

        $this->assertNotNull($meta);
        $this->assertSame('custom-user-meta@example.com', $meta->email);
        $this->assertSame('Custom User Meta', $meta->name);
    }

    private function registerCustomBlueprint(): void
    {
        app(BlueprintRepository::class)->setFallback('custom_meerkat', fn () => Blueprint::makeFromFields([
            'body' => [
                'type' => 'textarea',
                'display' => 'Body',
                'validate' => 'required',
                'listable' => true,
            ],
            'display_name' => [
                'type' => 'text',
                'display' => 'Display Name',
                'validate' => 'required',
                'listable' => true,
            ],
            'contact_email' => [
                'type' => 'text',
                'input_type' => 'email',
                'display' => 'Contact Email',
                'validate' => 'required|email',
                'listable' => true,
            ],
            'mood' => [
                'type' => 'text',
                'display' => 'Mood',
                'listable' => true,
            ],
        ]));
    }

    private function registerAuthenticatedOnlyBlueprint(): void
    {
        app(BlueprintRepository::class)->setFallback('auth_custom_meerkat', fn () => Blueprint::makeFromFields([
            'body' => [
                'type' => 'textarea',
                'display' => 'Body',
                'validate' => 'required',
                'listable' => true,
            ],
            'mood' => [
                'type' => 'text',
                'display' => 'Mood',
                'listable' => true,
            ],
        ]));
    }
}
