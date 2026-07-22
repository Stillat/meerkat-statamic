<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Threads;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Antlers;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Services\ThreadResolver;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class SharingTest extends TestCase
{
    #[Test]
    public function scalar_and_v3_array_share_fields_resolve_the_owner_thread(): void
    {
        foreach ([['array', ['owner-array']], ['scalar', 'owner-scalar']] as [$suffix, $value]) {
            $owner = 'owner-'.$suffix;
            $this->entry($owner);
            $sharer = $this->entry('sharer-'.$suffix, ['meerkat_share_comments' => $value]);
            CommentFactory::new()->threadId($owner)->text('shared '.$suffix)->data(['comment' => 'shared '.$suffix])->published()->create();

            $result = (string) Antlers::parse('{{ meerkat:comments }}[{{ comment }}]{{ /meerkat:comments }}', $this->requireObject($sharer->toAugmentedArray()), true);
            $this->assertStringContainsString('[shared '.$suffix.']', $result);
        }
    }

    #[Test]
    public function explicit_parameter_overrides_sharing_and_share_field_configuration_is_respected(): void
    {
        $this->entry('share-owner');
        $this->entry('explicit-owner');
        $sharer = $this->entry('share-config', ['meerkat_share_comments' => 'share-owner', 'reads_from' => 'explicit-owner']);
        CommentFactory::new()->threadId('share-owner')->text('share body')->data(['comment' => 'share body'])->published()->create();
        CommentFactory::new()->threadId('explicit-owner')->text('explicit body')->data(['comment' => 'explicit body'])->published()->create();

        $explicit = (string) Antlers::parse('{{ meerkat:comments thread="explicit-owner" }}[{{ comment }}]{{ /meerkat:comments }}', $this->requireObject($sharer->toAugmentedArray()), true);
        $this->assertStringContainsString('explicit body', $explicit);
        $this->assertStringNotContainsString('share body', $explicit);

        config()->set('meerkat.publishing.share_field', 'reads_from');
        $renamed = (string) Antlers::parse('{{ meerkat:comments }}[{{ comment }}]{{ /meerkat:comments }}', $this->requireObject($sharer->toAugmentedArray()), true);
        $this->assertStringContainsString('explicit body', $renamed);

        config()->set('meerkat.publishing.share_field');
        $disabled = (string) Antlers::parse('{{ meerkat:comments }}[{{ comment }}]{{ /meerkat:comments }}', $this->requireObject($sharer->toAugmentedArray()), true);
        $this->assertStringNotContainsString('share body', $disabled);
    }

    #[Test]
    public function submission_from_a_sharing_entry_persists_to_the_owner_thread(): void
    {
        $this->entry('write-owner');
        $this->entry('write-sharer', ['meerkat_share_comments' => ['write-owner']]);

        $this->submitComment(['_meerkat_context' => 'write-owner', 'comment' => 'posted from sharer', 'name' => 'P', 'email' => 'p@example.com'])->assertRedirect();

        $this->assertSame('write-owner', Comment::query()->where('comment_text', 'posted from sharer')->firstOrFail()->thread_id);
    }

    #[Test]
    public function debug_tag_reports_effective_share_resolution(): void
    {
        $this->entry('debug-owner');
        $sharer = $this->entry('debug-sharer', ['meerkat_share_comments' => 'debug-owner']);

        $result = (string) Antlers::parse('{{ meerkat:debug }}{{ rows }}[{{ label }}={{ value }}]{{ /rows }}{{ /meerkat:debug }}', $this->requireObject($sharer->toAugmentedArray()), true);

        foreach (['[Is sharing context=yes]', '[Share field=meerkat_share_comments]', '[Effective thread id=debug-owner]'] as $signal) {
            $this->assertStringContainsString($signal, $result);
        }
    }

    #[Test]
    public function entry_api_endpoints_use_the_shared_thread(): void
    {
        $this->entry('api-share-owner');
        $this->entry('api-share-alias', ['meerkat_share_comments' => 'api-share-owner']);
        CommentFactory::new()->threadId('api-share-owner')->text('shared api body')->published()->create();

        $this->getJson('/api/meerkat/entries/api-share-alias/roots')
            ->assertOk()
            ->assertJsonPath('data.0.comment_text', 'shared api body')
            ->assertJsonPath('data.0.thread_id', 'api-share-owner');
    }

    #[Test]
    public function sharing_chains_resolve_and_cycles_fall_back_to_the_requested_entry(): void
    {
        $owner = $this->entry('chain-owner');
        $middle = $this->entry('chain-middle', ['meerkat_share_comments' => $owner->id()]);
        $alias = $this->entry('chain-alias', ['meerkat_share_comments' => [$middle->id()]]);
        $resolver = app(ThreadResolver::class);

        $this->assertSame('chain-owner', $resolver->forEntry($alias));

        $first = $this->entry('cycle-first', ['meerkat_share_comments' => 'cycle-second']);
        $this->entry('cycle-second', ['meerkat_share_comments' => 'cycle-first']);

        $this->assertSame('cycle-first', $resolver->forEntry($first));
    }

    /** @param array<string, mixed> $data */
    private function entry(string $id, array $data = []): Entry
    {
        $collection = $this->makeStatamicCollection('blog');
        $collection->title('Blog');
        $collection->save();
        $entry = $this->makeStatamicEntry();
        $entry->collection('blog');
        $entry->slug($id);
        $entry->id($id);
        $entry->data(array_merge(['title' => 'Entry '.$id], $data));
        $entry->save();

        return $entry;
    }
}
