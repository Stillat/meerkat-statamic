<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Tests\TestCase;

class CloseWindowTest extends TestCase
{
    private int $entryCounter = 0;

    #[Test]
    public function zero_window_disables_automatic_closure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
        Settings::set('publishing.automatically_close_comments', 0);

        $this->assertTrue($this->repo()->areCommentsEnabledForEntry($this->entry(Carbon::now()->subYears(5))));
    }

    #[Test]
    public function dated_window_handles_inside_boundary_expired_missing_and_future_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
        Settings::set('publishing.automatically_close_comments', 30);

        foreach ([
            [Carbon::now()->subDays(15), true],
            [Carbon::now()->subDays(30), true],
            [Carbon::now()->subDays(45), false],
            [null, true],
            [Carbon::now()->addDays(10), true],
        ] as [$date, $enabled]) {
            $this->assertSame($enabled, $this->repo()->areCommentsEnabledForEntry($this->entry($date)));
        }
    }

    private function entry(?Carbon $date): Entry
    {
        $collection = $this->makeStatamicCollection('articles');
        $collection->title('Articles');
        $collection->dated(true);
        $collection->save();
        $id = 'close-window-'.++$this->entryCounter;
        $entry = $this->makeStatamicEntry();
        $entry->collection('articles');
        $entry->slug($id);
        $entry->id($id);
        $entry->data(['title' => 'Test']);
        if ($date instanceof Carbon) {
            $entry->date($date);
        }
        $entry->save();

        return $entry;
    }
}
