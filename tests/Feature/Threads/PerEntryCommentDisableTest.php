<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Threads;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Antlers;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class PerEntryCommentDisableTest extends TestCase
{
    #[Test]
    public function configured_field_only_closes_entries_with_a_truthy_flag(): void
    {
        config()->set('meerkat.publishing.entry_disable_field', 'comments_closed');
        $closed = $this->entry('closed', ['comments_closed' => true]);
        $open = $this->entry('open', ['comments_closed' => false]);
        $unset = $this->entry('unset');

        $this->assertTrue($this->repo()->isExplicitlyDisabledForEntry($closed));
        $this->assertFalse($this->repo()->areCommentsEnabledForEntry($closed));
        $this->assertFalse($this->repo()->isExplicitlyDisabledForEntry($open));
        $this->assertTrue($this->repo()->areCommentsEnabledForEntry($open));
        $this->assertFalse($this->repo()->isExplicitlyDisabledForEntry($unset));
    }

    #[Test]
    public function disabling_the_feature_ignores_the_entry_field(): void
    {
        config()->set('meerkat.publishing.entry_disable_field');
        $entry = $this->entry('feature-off', ['comments_closed' => true]);

        $this->assertFalse($this->repo()->isExplicitlyDisabledForEntry($entry));
        $this->assertTrue($this->repo()->areCommentsEnabledForEntry($entry));
    }

    #[Test]
    public function explicit_disable_and_auto_close_window_remain_independent_inputs(): void
    {
        config()->set('meerkat.publishing.entry_disable_field', 'comments_closed');
        Settings::set('publishing.automatically_close_comments', 1);
        $old = $this->entry('old', [], Carbon::now()->subYears(5));
        $recentClosed = $this->entry('recent-closed', ['comments_closed' => true]);

        $this->assertFalse($this->repo()->isExplicitlyDisabledForEntry($old));
        $this->assertFalse($this->repo()->areCommentsEnabledForEntry($old));
        $this->assertTrue($this->repo()->isExplicitlyDisabledForEntry($recentClosed));
        $this->assertFalse($this->repo()->areCommentsEnabledForEntry($recentClosed));
    }

    #[Test]
    public function comments_enabled_tag_uses_the_entry_disable_rule(): void
    {
        config()->set('meerkat.publishing.entry_disable_field', 'comments_closed');
        $this->entry('tag-disabled', ['comments_closed' => true]);
        $this->createThread('tag-disabled');

        $result = (string) Antlers::parse('{{ if {meerkat:comments_enabled thread="tag-disabled"} }}OPEN{{ else }}CLOSED{{ /if }}', [], true);

        $this->assertSame('CLOSED', trim($result));
    }

    #[Test]
    public function template_reply_capability_respects_explicit_and_automatic_entry_closures(): void
    {
        config()->set('meerkat.publishing.entry_disable_field', 'comments_closed');
        Settings::set('publishing.automatically_close_comments', 1);

        $this->entry('reply-open');
        $this->entry('reply-closed', ['comments_closed' => true]);
        $this->entry('reply-aged', [], Carbon::now()->subDays(2));

        foreach (['reply-open', 'reply-closed', 'reply-aged'] as $thread) {
            CommentFactory::new()->threadId($thread)->published()->create();
        }

        $render = fn (string $thread): string => trim((string) Antlers::parse(
            '{{ meerkat:comments thread="'.$thread.'" }}{{ if current_user:can_reply }}Y{{ else }}N{{ /if }}{{ /meerkat:comments }}',
            [],
            true,
        ));

        $this->assertSame('Y', $render('reply-open'));
        $this->assertSame('N', $render('reply-closed'));
        $this->assertSame('N', $render('reply-aged'));
    }

    /** @param array<string, mixed> $data */
    private function entry(string $id, array $data = [], ?Carbon $date = null): Entry
    {
        $collection = $this->makeStatamicCollection('articles');
        $collection->title('Articles');
        $collection->dated(true);
        $collection->save();
        $entry = $this->makeStatamicEntry();
        $entry->collection('articles');
        $entry->slug($id);
        $entry->id($id);
        $entry->data(array_merge(['title' => 'T'], $data));
        $entry->date($date ?? Carbon::now());
        $entry->save();

        return $entry;
    }
}
