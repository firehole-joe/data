<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Tests\TestCase;

class FeedScheduleTest extends TestCase
{
    /**
     * @return Collection<int, Event>
     */
    private function scheduledEvents(): Collection
    {
        return collect($this->app->make(Schedule::class)->events());
    }

    private function eventFor(string $needle): Event
    {
        $event = $this->scheduledEvents()->first(
            fn (Event $event) => str_contains($event->command ?? '', $needle)
        );

        $this->assertNotNull($event, "No scheduled task found for [{$needle}].");

        return $event;
    }

    public function test_rsr_feed_sync_is_scheduled_daily_at_0400(): void
    {
        $event = $this->eventFor('feed:sync rsr --force');

        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        $this->assertSame(storage_path('logs/feed-rsr.log'), $event->output);
        $this->assertTrue($event->shouldAppendOutput);
    }

    public function test_zanders_feed_sync_is_scheduled_daily_at_0430(): void
    {
        $event = $this->eventFor('feed:sync zanders --force');

        $this->assertSame('30 4 * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        $this->assertSame(storage_path('logs/feed-zanders.log'), $event->output);
        $this->assertTrue($event->shouldAppendOutput);
    }

    public function test_chattanooga_feed_sync_is_scheduled_daily_at_0500(): void
    {
        $event = $this->eventFor('feed:sync chattanooga --force');

        $this->assertSame('0 5 * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        $this->assertSame(storage_path('logs/feed-chattanooga.log'), $event->output);
        $this->assertTrue($event->shouldAppendOutput);
    }

    public function test_davidsons_feed_sync_is_scheduled_daily_at_0530(): void
    {
        $event = $this->eventFor('feed:sync davidsons --force');

        $this->assertSame('30 5 * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        $this->assertSame(storage_path('logs/feed-davidsons.log'), $event->output);
        $this->assertTrue($event->shouldAppendOutput);
    }

    public function test_scheduled_feed_syncs_do_not_share_a_mutex(): void
    {
        $mutexes = collect(['rsr', 'zanders', 'chattanooga', 'davidsons'])
            ->map(fn (string $slug) => $this->eventFor("feed:sync {$slug} --force")->mutexName());

        $this->assertCount(4, $mutexes->unique(), 'each scheduled feed has its own overlap mutex');
    }
}
