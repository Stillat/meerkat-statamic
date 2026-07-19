<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\ControlPanel;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ReplyDisableEnforcementTest extends TestCase
{
    private function adminUser(): User
    {
        $collection = $this->makeStatamicCollection('articles');
        $collection->title('Articles');
        $collection->dated(true);
        $collection->save();

        return $this->makeAdmin('cp-disable-admin', 'cp-disable@example.com', actingAs: false, data: ['name' => 'Admin']);
    }

    /** @param array<string, mixed> $data */
    private function entryWith(string $id, array $data = [], ?Carbon $date = null): Entry
    {
        $entry = $this->makeStatamicEntry();
        $entry->collection('articles');
        $entry->slug($id);
        $entry->id($id);
        $entry->data(array_merge(['title' => 'T'], $data));
        $entry->date($date ?? Carbon::now());

        $entry->save();

        return $entry;
    }

    #[Test]
    public function cp_reply_is_rejected_when_entry_has_comments_closed_flag(): void
    {
        config()->set('meerkat.publishing.entry_disable_field', 'comments_closed');

        $user = $this->adminUser();
        $this->actingAs($user);

        $entry = $this->entryWith('cp-locked', ['comments_closed' => true]);

        $parent = CommentFactory::new()
            ->threadId($this->requireString($entry->id()))
            ->collection('articles')
            ->author('A', 'a@e.com')
            ->text('parent')
            ->data(['comment' => 'parent'])
            ->depth(0)
            ->published()
            ->create();

        $this->postJson(cp_route('meerkat.comment.reply', ['parent' => $parent->id]), [
            'comment' => 'admin trying to reply',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('meerkat::validation.comments_closed'));
    }

    #[Test]
    public function cp_reply_succeeds_when_entry_was_only_auto_closed_by_window(): void
    {
        Settings::set('publishing.automatically_close_comments', 30);

        $user = $this->adminUser();
        $this->actingAs($user);

        $entry = $this->entryWith('cp-old', [], Carbon::now()->subYears(5));

        $parent = CommentFactory::new()
            ->threadId($this->requireString($entry->id()))
            ->collection('articles')
            ->author('A', 'a@e.com')
            ->text('parent')
            ->data(['comment' => 'parent'])
            ->depth(0)
            ->published()
            ->create();

        $response = $this->postJson(cp_route('meerkat.comment.reply', ['parent' => $parent->id]), [
            'comment' => 'admin reviving the thread',
        ]);

        $this->assertSame(200, $response->status(), 'Expected 200; got '.$response->status().' body: '.$response->getContent());
    }
}
