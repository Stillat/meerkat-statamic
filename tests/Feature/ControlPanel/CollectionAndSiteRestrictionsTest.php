<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\ControlPanel;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Site;
use Stillat\Meerkat\Concerns\GetsMeerkatPermissions;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Permissions\Permissions;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class CollectionAndSiteRestrictionsTest extends TestCase
{
    #[Test]
    public function the_comment_index_only_exposes_accessible_collections(): void
    {
        $this->makeBlogAndDocsCollections();
        $this->comment('blog-thread', 'blog', 'default', 'Blog comment');
        $this->comment('docs-thread', 'docs', 'default', 'Docs comment');

        $user = $this->userWithPermissions('view comments', 'view blog entries');
        $rows = $this->requireRows($this->actingAs($user)
            ->getJson(cp_route('meerkat.cp.comments.index'))
            ->assertOk()
            ->json('data'));

        $this->assertCount(1, $rows);
        $this->assertSame('Blog comment', $rows[0]['comment_text']);
    }

    #[Test]
    public function inaccessible_collections_cannot_be_mutated_or_replied_to(): void
    {
        $this->makeBlogAndDocsCollections();
        $comment = $this->comment('docs-thread', 'docs', 'default', 'Docs comment');

        $user = $this->userWithPermissions(
            'edit comments',
            'submit comments',
            'view blog entries',
            'access default site',
        );
        $this->actingAs($user);

        $this->putJson(
            cp_route('meerkat.comment.update', ['id' => $comment->id]),
            ['comment' => 'edited', 'name' => 'A', 'email' => 'a@example.com'],
        )->assertForbidden();

        $this->getJson(cp_route('meerkat.comment.reply-data', ['parent' => $comment->id]))
            ->assertForbidden();
    }

    #[Test]
    public function single_site_mode_is_not_reported_as_restricted(): void
    {
        $this->actingAs($this->userWithPermissions('view comments'));

        $permissions = $this->resolveUserPermissions();

        $this->assertFalse($permissions->hasSiteRestrictions);
        $this->assertSame(['default'], $permissions->accessibleSites);
    }

    #[Test]
    public function the_comment_index_only_exposes_accessible_sites(): void
    {
        $this->enableMultiSite();
        $collection = $this->makeStatamicCollection('blog');
        $collection->title('Blog');
        $collection->sites(['default', 'fr']);
        $collection->save();
        $this->comment('default-thread', 'blog', 'default', 'Default comment');
        $this->comment('fr-thread', 'blog', 'fr', 'French comment');

        $user = $this->userWithPermissions(
            'view comments',
            'view blog entries',
            'access default site',
        );
        $rows = $this->requireRows($this->actingAs($user)
            ->getJson(cp_route('meerkat.cp.comments.index'))
            ->assertOk()
            ->json('data'));

        $this->assertCount(1, $rows);
        $this->assertSame('Default comment', $rows[0]['comment_text']);
    }

    #[Test]
    public function inaccessible_sites_cannot_be_mutated(): void
    {
        $this->enableMultiSite();
        $collection = $this->makeStatamicCollection('blog');
        $collection->title('Blog');
        $collection->sites(['default', 'fr']);
        $collection->save();
        $comment = $this->comment('fr-thread', 'blog', 'fr', 'French comment');

        $user = $this->userWithPermissions(
            'edit comments',
            'view blog entries',
            'access default site',
        );

        $this->actingAs($user)->putJson(
            cp_route('meerkat.comment.update', ['id' => $comment->id]),
            ['comment' => 'edited', 'name' => 'A', 'email' => 'a@example.com'],
        )->assertForbidden();
    }

    #[Test]
    public function permission_metadata_reports_partial_collection_and_site_grants(): void
    {
        $this->enableMultiSite();
        $this->makeBlogAndDocsCollections();

        $user = $this->userWithPermissions(
            'view comments',
            'view blog entries',
            'access default site',
        );
        $this->actingAs($user);

        $permissions = $this->resolveUserPermissions();

        $this->assertTrue($permissions->hasCollectionRestrictions);
        $this->assertSame(['blog'], $permissions->accessibleCollections);
        $this->assertTrue($permissions->hasSiteRestrictions);
        $this->assertSame(['default'], $permissions->accessibleSites);
    }

    private function makeBlogAndDocsCollections(): void
    {
        $blog = $this->makeStatamicCollection('blog');
        $blog->title('Blog');
        $blog->save();
        $docs = $this->makeStatamicCollection('docs');
        $docs->title('Docs');
        $docs->save();
    }

    private function comment(string $thread, string $collection, string $site, string $text): Comment
    {
        return CommentFactory::new()
            ->threadId($thread)
            ->collection($collection)
            ->site($site)
            ->author('Author', strtolower(str_replace(' ', '-', $text)).'@example.com')
            ->text($text)
            ->data(['comment' => $text])
            ->published()
            ->create();
    }

    private function enableMultiSite(): void
    {
        config()->set('statamic.system.multisite', true);

        Site::setSites([
            'default' => [
                'name' => 'Default',
                'locale' => 'en_US',
                'url' => 'http://localhost/',
            ],
            'fr' => [
                'name' => 'French',
                'locale' => 'fr_FR',
                'url' => 'http://localhost/fr/',
            ],
        ]);
    }

    private function resolveUserPermissions(): Permissions
    {
        $resolver = new class
        {
            use GetsMeerkatPermissions;

            public function call(): Permissions
            {
                return $this->getPermissions();
            }
        };

        return $resolver->call();
    }
}
