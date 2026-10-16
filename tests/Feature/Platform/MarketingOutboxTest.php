<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use App\Models\MarketingOutbox;
use App\Notifications\BackInStockNotification;
use App\Services\Marketing\MarketingOutboxService;
use App\Support\MaintenanceTasks;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MarketingOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_marketing_outbox_is_registered_in_maintenance_tasks_every_minute(): void
    {
        $tasks = collect(MaintenanceTasks::all());
        $outboxTask = $tasks->firstWhere('name', 'marketing-outbox');

        $this->assertNotNull($outboxTask);
        $this->assertSame('marketing:send', $outboxTask['command']);
        $this->assertSame('* * * * *', $outboxTask['cron']);
    }

    public function test_marketing_outbox_sends_oldest_first_up_to_10_per_run(): void
    {
        $customer = Customer::factory()->create();

        for ($i = 1; $i <= 15; $i++) {
            MarketingOutbox::create([
                'kind' => 'back_in_stock',
                'customer_id' => $customer->id,
                'email' => "test{$i}@example.com",
                'locale' => 'en',
                'payload' => ['book_id' => $i, 'book_title' => "Book {$i}", 'format' => 'hardcover'],
                'status' => 'pending',
            ]);
        }

        $service = app(MarketingOutboxService::class);
        $sentCount = $service->sendPending(10);

        $this->assertSame(10, $sentCount);
        $this->assertSame(10, MarketingOutbox::where('status', 'sent')->count());
        $this->assertSame(5, MarketingOutbox::where('status', 'pending')->count());

        // The first 10 rows by ID must be the ones sent
        $sentIds = MarketingOutbox::where('status', 'sent')->pluck('id')->all();
        $expectedFirstTen = MarketingOutbox::orderBy('id', 'asc')->take(10)->pluck('id')->all();
        $this->assertSame($expectedFirstTen, $sentIds);
    }

    public function test_marketing_outbox_strictly_enforces_daily_cap_and_resumes_next_day(): void
    {
        config(['shop.marketing_daily_limit' => 60]);
        Carbon::setTestNow('2026-10-16 10:00:00');

        $customer = Customer::factory()->create();

        for ($i = 1; $i <= 65; $i++) {
            MarketingOutbox::create([
                'kind' => 'back_in_stock',
                'customer_id' => $customer->id,
                'email' => "user{$i}@example.com",
                'locale' => 'en',
                'payload' => ['book_id' => $i, 'book_title' => "Book {$i}", 'format' => 'hardcover'],
                'status' => 'pending',
            ]);
        }

        $service = app(MarketingOutboxService::class);

        // Run until daily limit is exhausted
        for ($run = 1; $run <= 8; $run++) {
            $service->sendPending(10);
        }

        // Today must have exactly 60 sent rows, 5 remaining pending
        $this->assertSame(60, MarketingOutbox::where('status', 'sent')->count());
        $this->assertSame(5, MarketingOutbox::where('status', 'pending')->count());

        // Running again today sends 0
        $this->assertSame(0, $service->sendPending(10));

        // Advance to tomorrow in shop timezone
        Carbon::setTestNow('2026-10-17 08:00:00');

        // Now the 5 remaining rows can be sent
        $sentTomorrow = $service->sendPending(10);
        $this->assertSame(5, $sentTomorrow);
        $this->assertSame(65, MarketingOutbox::where('status', 'sent')->count());
        $this->assertSame(0, MarketingOutbox::where('status', 'pending')->count());
    }

    public function test_marketing_outbox_retries_failures_up_to_3_times_then_marks_failed(): void
    {
        $customer = Customer::factory()->create();

        $row = MarketingOutbox::create([
            'kind' => 'back_in_stock',
            'customer_id' => $customer->id,
            'email' => 'fail@example.com',
            'locale' => 'en',
            'payload' => ['book_id' => 1, 'book_title' => 'Fail Book', 'format' => 'paperback'],
            'status' => 'pending',
        ]);

        // Mock notification dispatcher to throw an exception
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('sendNow')
            ->andThrow(new \RuntimeException('SMTP connection refused'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $service = app(MarketingOutboxService::class);

        // Attempt 1
        $service->sendPending(10);
        $row->refresh();
        $this->assertSame(1, $row->attempts);
        $this->assertSame('pending', $row->status);
        $this->assertStringContainsString('SMTP connection refused', $row->last_error);

        // Attempt 2
        $service->sendPending(10);
        $row->refresh();
        $this->assertSame(2, $row->attempts);
        $this->assertSame('pending', $row->status);

        // Attempt 3: reached max 3 attempts -> marked failed
        $service->sendPending(10);
        $row->refresh();
        $this->assertSame(3, $row->attempts);
        $this->assertSame('failed', $row->status);
    }

    public function test_back_in_stock_email_links_to_the_book_page_by_id(): void
    {
        config(['shop.url' => 'https://shop.example']);
        $mail = (new BackInStockNotification(['book_id' => 42, 'book_title' => 'Dune', 'format' => 'paperback', 'customer_name' => 'Dara']))
            ->toMail(new \stdClass);

        $this->assertSame('https://shop.example/books/42', $mail->actionUrl);
    }
}
