<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Comments;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class TombstonesTest extends TestCase
{
    #[Test]
    public function tombstoning_preserves_rows_children_reply_counts_and_idempotency(): void
    {
        [$parent, $childA, $childB] = $this->makeTree('tombstone-invariants');
        $this->assertSame(2, $this->requireValue($parent->fresh())->replies_count);

        Comments::deleteComment($childA->id);
        $this->assertSame(2, $this->requireValue($parent->fresh())->replies_count);

        $this->assertTrue(Comments::deleteComment($parent->id, 'off topic'));
        $firstRemovedAt = $this->requireValue($parent->fresh())->removed_at;
        $this->assertNotNull($firstRemovedAt);
        $this->assertTrue(Comments::deleteComment($parent->id));

        $fresh = $this->requireValue($parent->fresh());
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->is_removed);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame('off topic', $fresh->removed_reason);
        $this->assertSame($firstRemovedAt->toIso8601String(), $this->requireValue($fresh->removed_at)->toIso8601String());
        $this->assertFalse($this->requireValue($childB->fresh())->is_removed);
        $this->assertNull($this->requireValue($childB->fresh())->deleted_at);
    }

    #[Test]
    public function public_api_hides_a_tombstoned_subtree_without_hiding_sibling_branches(): void
    {
        $this->createEntry(['id' => 'api-tombstone-tree']);
        [$parent, $childA, $childB] = $this->makeTree('api-tombstone-tree');
        $grandchild = $this->comment('api-tombstone-tree', 'Grandchild', 'grand@example.com', $childA->id, 2);
        $sibling = $this->comment('api-tombstone-tree', 'Sibling root', 'sibling@example.com');
        Comments::deleteComment($parent->id);

        $comments = $this->getJson('/api/meerkat/threads/api-tombstone-tree/comments')
            ->assertOk()
            ->json('comments');
        $this->assertIsArray($comments);
        $ids = collect($comments)->pluck('id')->all();

        $this->assertContains($sibling->id, $ids);
        foreach ([$parent, $childA, $childB, $grandchild] as $hidden) {
            $this->assertNotContains($hidden->id, $ids);
        }
    }

    #[Test]
    public function children_endpoint_filters_tombstoned_children(): void
    {
        $this->createEntry(['id' => 'api-tombstone-children']);
        [$parent, $removed, $visible] = $this->makeTree('api-tombstone-children');
        Comments::deleteComment($removed->id);

        $comments = $this->getJson(
            "/api/meerkat/threads/api-tombstone-children/children/{$parent->id}",
        )->assertOk()->json('data');
        $this->assertIsArray($comments);
        $ids = collect($comments)->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($removed->id, $ids);
    }

    #[Test]
    public function api_inclusion_flags_are_privileged_and_define_tombstone_subtree_visibility(): void
    {
        $this->createEntry(['id' => 'api-tombstone-flags']);
        [$parent, $childA, $childB] = $this->makeTree('api-tombstone-flags');
        Comments::deleteComment($parent->id, 'removed reason');

        $anonymous = $this->apiIds(
            'api-tombstone-flags',
            '?include_tombstones=true&include_tombstone_replies=true',
        );
        $this->assertNotContains($parent->id, $anonymous);
        $this->assertNotContains($childA->id, $anonymous);

        $this->actAsAdmin('api-flags');
        $this->assertNotContains($parent->id, $this->apiIds('api-tombstone-flags'));

        $tombstonesOnlyResponse = $this->getJson(
            '/api/meerkat/threads/api-tombstone-flags/comments?include_tombstones=true',
        )->assertOk();
        $comments = $this->requireRows($tombstonesOnlyResponse->json('comments'));
        $tombstone = collect($comments)->firstWhere('id', $parent->id);
        $this->assertNotNull($tombstone);
        $tombstone = $this->requireObject($tombstone);
        $this->assertFalse(collect($comments)->contains('id', $childA->id));
        $this->assertFalse(collect($comments)->contains('id', $childB->id));
        $this->assertTrue($tombstone['is_removed']);
        $this->assertSame('removed reason', $tombstone['removed_reason']);

        foreach ([
            '?include_tombstones=true&include_tombstone_replies=true',
            '?include_tombstone_replies=true',
        ] as $query) {
            $ids = $this->apiIds('api-tombstone-flags', $query);
            $this->assertContains($parent->id, $ids);
            $this->assertContains($childA->id, $ids);
            $this->assertContains($childB->id, $ids);
        }
    }

    #[Test]
    public function remove_subtree_tombstones_only_unremoved_descendants_and_preserves_siblings(): void
    {
        [$parent, $childA, $childB] = $this->makeTree('remove-subtree');
        $grandchild = $this->comment('remove-subtree', 'Grandchild', 'grand@example.com', $childA->id, 2);
        $sibling = $this->comment('remove-subtree', 'Sibling root', 'sibling@example.com');
        Comments::deleteComment($childA->id);

        $this->assertSame(3, Comments::removeSubtree($parent->id, 'coordinated spam'));

        foreach ([$parent, $childB, $grandchild] as $removed) {
            $this->assertTrue($this->requireValue($removed->fresh())->is_removed);
            $this->assertSame('coordinated spam', $this->requireValue($removed->fresh())->removed_reason);
        }
        $this->assertFalse($this->requireValue($sibling->fresh())->is_removed);
    }

    #[Test]
    public function restore_clears_tombstone_metadata_and_rejects_live_rows(): void
    {
        $removed = $this->comment('restore-tombstone', 'Removed', 'removed@example.com');
        $live = $this->comment('restore-tombstone', 'Live', 'live@example.com');
        Comments::deleteComment($removed->id, 'mistake');

        $this->assertTrue(Comments::restoreComment($removed->id));
        $fresh = $this->requireValue($removed->fresh());
        $this->assertFalse($fresh->is_removed);
        $this->assertNull($fresh->removed_at);
        $this->assertNull($fresh->removed_by);
        $this->assertNull($fresh->removed_reason);
        $this->assertFalse(Comments::restoreComment($live->id));
    }

    #[Test]
    public function force_delete_removes_tombstoned_and_soft_deleted_rows_and_cascades_descendants(): void
    {
        [$parent, $childA, $childB] = $this->makeTree('force-delete-tree');
        Comments::deleteComment($parent->id);
        $this->assertTrue(Comments::forceDeleteComment($parent->id));

        foreach ([$parent, $childA, $childB] as $deleted) {
            $this->assertNull(Comment::withTrashed()->find($deleted->id));
        }

        $softDeleted = $this->comment('force-delete-soft', 'Soft deleted', 'soft@example.com');
        $softDeleted->delete();
        $this->assertTrue(Comments::forceDeleteComment($softDeleted->id));
        $this->assertNull(Comment::withTrashed()->find($softDeleted->id));
        $this->assertFalse(Comments::forceDeleteComment(999_999));
    }

    #[Test]
    public function antlers_visibility_never_exposes_removed_spam_or_trashed_comments_without_privilege(): void
    {
        $this->createEntry(['id' => 'tag-visibility']);
        [$tombstone] = $this->makeTree('tag-visibility');
        Comments::deleteComment($tombstone->id);
        $this->comment('tag-visibility', 'Visible', 'visible@example.com');
        CommentFactory::new()->threadId('tag-visibility')->author('Spam', 'spam@example.com')->text('spam body')->data(['comment' => 'spam body'])->published()->spam()->create();
        $trashed = $this->comment('tag-visibility', 'Trashed', 'trashed@example.com');
        $trashed->delete();

        $template = '{{ meerkat:comments thread="tag-visibility" include_tombstones="true" include_trashed="true" }}[{{ id }}:{{ comment }}]{{ /meerkat:comments }}';
        $anonymous = (string) Antlers::parse($template, [], true);
        $this->assertStringContainsString('[', $anonymous);
        $this->assertStringContainsString('visible body', $anonymous);
        $this->assertStringNotContainsString($tombstone->id.':', $anonymous);
        $this->assertStringNotContainsString('spam body', $anonymous);
        $this->assertStringNotContainsString('trashed body', $anonymous);

        $this->actAsAdmin('tag-flags');
        $privileged = (string) Antlers::parse($template, [], true);
        $this->assertStringContainsString($tombstone->id.':parent author body', $privileged);
    }

    /** @return array{Comment, Comment, Comment} */
    private function makeTree(string $threadId): array
    {
        $parent = $this->comment($threadId, 'Parent Author', 'parent@example.com');
        $childA = $this->comment($threadId, 'Child A', 'a@example.com', $parent->id, 1);
        $childB = $this->comment($threadId, 'Child B', 'b@example.com', $parent->id, 1);

        return [$parent, $childA, $childB];
    }

    private function comment(
        string $threadId,
        string $author,
        string $email,
        ?int $parentId = null,
        int $depth = 0,
    ): Comment {
        $factory = CommentFactory::new()
            ->threadId($threadId)
            ->author($author, $email)
            ->text(strtolower($author).' body')
            ->data(['comment' => strtolower($author).' body'])
            ->depth($depth)
            ->published();

        if ($parentId !== null) {
            $factory->parent($parentId);
        }

        return $factory->create();
    }

    /** @return list<int> */
    private function apiIds(string $thread, string $query = ''): array
    {
        $comments = $this->getJson("/api/meerkat/threads/{$thread}/comments{$query}")
            ->assertOk()
            ->json('comments');
        $this->assertIsArray($comments);

        return $this->requireIntegerList(collect($comments)->pluck('id')->all());
    }

    private function actAsAdmin(string $suffix): void
    {
        $this->makeAdmin("tombstone-admin-{$suffix}", "{$suffix}@example.com");
    }
}
