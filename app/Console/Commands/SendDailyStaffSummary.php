<?php

namespace App\Console\Commands;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Services\Admin\DashboardInsightsService;
use App\Services\Admin\DashboardService;
use App\Services\Telegram\OpsAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendDailyStaffSummary extends Command
{
    protected $signature = 'ops:daily-summary';

    protected $description = 'Send the daily shop summary to the staff group once';

    public function handle(DashboardService $dashboard, DashboardInsightsService $insights, OpsAlerts $alerts): int
    {
        $chat = (string) config('services.ops_alerts.staff_chat_id');
        if ($chat === '' || (string) config('services.ops_alerts.token') === '') {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now('Asia/Phnom_Penh');
        $day = ($now->hour < 20 ? $now->subDay() : $now)->startOfDay();
        $current = $dashboard->summary($day, $day->addDay(), false);
        $previous = $dashboard->summary($day->subDay(), $day, false);
        $counts = $insights->counts();
        $waiting = DB::table('orders')->whereNull('deleted_at')->where('is_waiting_stock', true)
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Paid->value, OrderStatus::Processing->value])->count();
        $waitingCopies = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->whereNull('orders.deleted_at')->where('orders.is_waiting_stock', true)
            ->whereIn('orders.status', [OrderStatus::Pending->value, OrderStatus::Paid->value, OrderStatus::Processing->value])
            ->whereIn(DB::raw('COALESCE(order_items.format, book_variants.format)'), [BookFormat::Paperback->value, BookFormat::Hardcover->value])
            ->sum('order_items.quantity');
        $chats = DB::table('support_conversations')->whereIn('status', ['open', 'pending'])->count();

        $message = implode("\n", [
            "Bookly — {$day->toDateString()} (Phnom Penh)",
            "Today / ថ្ងៃនេះ: {$current['orders_placed']} orders / ការបញ្ជាទិញ, $".$current['revenue']['net_usd'].' net revenue / ចំណូលសុទ្ធ',
            "Yesterday / ម្សិលមិញ: {$previous['orders_placed']} orders / ការបញ្ជាទិញ, $".$previous['revenue']['net_usd'].' net revenue / ចំណូលសុទ្ធ',
            "Waiting orders / ការបញ្ជាទិញរង់ចាំស្តុក: {$waiting}",
            "Printed copies in waiting orders / សៀវភៅបោះពុម្ពក្នុងការបញ្ជាទិញរង់ចាំ: {$waitingCopies}",
            "Low stock / ស្តុកជិតអស់: {$counts['low_stock']}",
            "Open chats / ការជជែកមិនទាន់បិទ: {$chats}",
        ]);

        // Claim before calling Telegram: an uncertain network response must not create duplicate alerts.
        $claimed = DB::table('staff_daily_summaries')->insertOrIgnore(['summary_date' => $day->toDateString(), 'created_at' => now()]);
        if ($claimed === 1) {
            $result = $alerts->sendDirect($chat, $message);
            if (! $result['ok']) {
                // Provider descriptions may contain the request URL; keep only a numeric status.
                Log::warning('Staff daily summary could not be delivered', ['status' => (int) ($result['status'] ?? 0)]);
            }
        }

        return self::SUCCESS;
    }
}
