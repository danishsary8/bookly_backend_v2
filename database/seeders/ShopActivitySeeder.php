<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Publisher;
use App\Models\StaffUser;
use App\Models\Supplier;
use App\Services\Cart\CartService;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\CheckoutService;
use App\Services\Orders\OrderStatusService;
use App\Services\Returns\ReturnService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Six months of believable shop activity on top of any catalogue (owner 2026-11-17: "make it like a real data
 * tracker"), so the dashboard, Reports → Profit and Reports → Inventory have something real to show:
 *
 *  - 5 suppliers;
 *  - for every printed format on sale: 1-3 restocks over the last 180 days, each with a unit cost of 50-68% of the
 *    sell price and a supplier (this also sets the format's supplier cost, see InventoryService::restock);
 *  - ~2 orders a day from 8 seeded customers across Cambodia, walked through the real checkout and status flow:
 *    most delivered, some cancelled, the last few days still pending / processing / shipped.
 *
 *   php artisan db:seed --class=ShopActivitySeeder
 *
 * Runs once (it stops if its suppliers exist). Seeded customers use @bookly.test emails. On production it refuses
 * unless SEED_SHOP_ACTIVITY=yes is set for that one command, because the orders and costs are made up.
 */
class ShopActivitySeeder extends Seeder
{
    private const DAYS = 180;

    private const SUPPLIERS = [
        ['Mekong Book Supply', 'Sophea Ly', '012 401 220', 'Main English titles; delivers twice a week.'],
        ['Angkor Educational Distributors', 'Rithy Sok', '017 556 908', 'School and English-learning books.'],
        ['Phnom Penh Imports', 'Chenda Ouk', '010 778 341', 'Imported hardcovers, longer lead time.'],
        ['Tonle Sap Publishing', 'Visal Chea', '015 212 664', 'Khmer titles from local publishers.'],
        ['Riverside Wholesale', 'Nary Pich', '096 330 117', 'Clearance stock at lower prices.'],
    ];

    private const CUSTOMERS = [
        ['Sreyneang Touch', 'sreyneang@bookly.test', 'Street 271, House 40', 'Phnom Penh'],
        ['Bunthoeun Mao', 'bunthoeun@bookly.test', 'Street 2004, House 9', 'Phnom Penh'],
        ['Kanha Prum', 'kanha@bookly.test', 'Monivong Blvd 155', 'Phnom Penh'],
        ['Piseth Nhem', 'piseth@bookly.test', 'National Road 6, Km 3', 'Siem Reap'],
        ['Sokunthea Ros', 'sokunthea@bookly.test', 'Street 3, Svay Por', 'Battambang'],
        ['Ratha Keo', 'ratha@bookly.test', 'Riverside Road 12', 'Kampot'],
        ['Leakena Sam', 'leakena@bookly.test', 'Street 108, House 2', 'Kampong Cham'],
        ['Vannak Chhay', 'vannak@bookly.test', 'Victory Hill 7', 'Sihanoukville'],
    ];

    public function run(): void
    {
        if (app()->isProduction() && env('SEED_SHOP_ACTIVITY') !== 'yes') {
            throw new RuntimeException('ShopActivitySeeder makes up orders and costs. On production set SEED_SHOP_ACTIVITY=yes for this one command.');
        }
        if (Supplier::where('name', self::SUPPLIERS[0][0])->exists()) {
            $this->command?->info('Shop activity already seeded; nothing to do.');

            return;
        }
        $admin = StaffUser::where('role', 'admin')->orderBy('id')->first();
        $variants = BookVariant::query()
            ->where('is_active', true)
            ->whereIn('format', ['paperback', 'hardcover'])
            ->whereHas('book', fn ($q) => $q->where('status', 'live')->whereNull('deleted_at'))
            ->orderBy('id')->get();
        if (! $admin || $variants->isEmpty()) {
            throw new RuntimeException('Needs an admin and live printed books first (e.g. DemoSeeder or the real catalogue).');
        }

        mt_srand(2026);
        try {
            $suppliers = $this->suppliers();
            $this->restocks($variants, $suppliers, $admin);
            $customers = $this->customers();
            $this->orders($customers, $variants, $admin);
            $this->returns($customers, $admin);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @return list<Supplier> */
    private function suppliers(): array
    {
        // Distributors and wholesalers carry many publishers; Tonle Sap Publishing sells its own books direct.
        $out = array_map(fn ($s) => Supplier::create([
            'name' => $s[0], 'type' => str_contains($s[0], 'Publishing') ? 'publisher' : 'distributor',
            'contact_name' => $s[1], 'phone' => $s[2], 'note' => $s[3], 'is_active' => true,
        ]), self::SUPPLIERS);
        // The two catalogue publishers with the most books also sell to the shop direct (owner 2026-11-17).
        $publishers = Publisher::withCount('books')->orderByDesc('books_count')->limit(2)->get();
        foreach ($publishers as $i => $publisher) {
            if (Supplier::where('publisher_id', $publisher->id)->exists()) {
                continue;
            }
            $out[] = Supplier::create(['name' => "{$publisher->name} (direct)", 'type' => 'publisher', 'publisher_id' => $publisher->id, 'contact_name' => 'Sales desk', 'phone' => '023 990 1'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'note' => 'Buying direct at the publisher discount.', 'is_active' => true]);
        }

        return $out;
    }

    /** @return list<Customer> */
    private function customers(): array
    {
        $out = [];
        foreach (self::CUSTOMERS as $i => [$name, $email, $street, $city]) {
            $customer = Customer::firstOrCreate(['email' => $email], [
                'name' => $name, 'password_hash' => DemoSeeder::PASSWORD, 'phone' => '0889'.str_pad((string) (10000 + $i), 5, '0', STR_PAD_LEFT),
                'email_verified_at' => now()->subDays(self::DAYS + 10),
            ]);
            $customer->forceFill(['created_at' => now()->subDays(self::DAYS + 5 - $i * 20)])->save();
            if (! $customer->addresses()->exists()) {
                $customer->addresses()->create(['label' => 'Home', 'recipient_name' => $name, 'phone' => $customer->phone, 'address_line1' => $street, 'city' => $city, 'country' => 'Cambodia', 'is_default' => true]);
            }
            $out[] = $customer;
        }

        return $out;
    }

    /** 1-3 dated restocks per printed format, each with a supplier and a unit cost below the sell price. */
    private function restocks($variants, array $suppliers, StaffUser $admin): void
    {
        $inventory = app(InventoryService::class);
        foreach ($variants as $variant) {
            // A book whose publisher sells direct is usually bought there; others come from a distributor.
            $direct = collect($suppliers)->first(fn ($s) => $s->publisher_id && $s->publisher_id === $variant->book?->publisher_id);
            $supplier = $direct && mt_rand(1, 10) <= 7 ? $direct : $suppliers[mt_rand(0, count($suppliers) - 1)];
            $times = mt_rand(1, 3);
            // About 2 in 5 formats were topped up in the last month, so recent spending is not empty.
            $recent = mt_rand(1, 5) <= 2;
            $margin = mt_rand(50, 68) / 100;
            for ($n = 0; $n < $times; $n++) {
                $day = self::DAYS - (int) floor($n * self::DAYS / $times) - mt_rand(0, 10);
                Carbon::setTestNow(now()->startOfDay()->subDays(max(1, $day))->setTime(2, 30));
                // Prices drift a little between deliveries, like real invoices.
                $cost = round((float) $variant->price_usd * ($margin + mt_rand(-3, 3) / 100), 2);
                $inventory->restock($variant, mt_rand(6, 24), $admin, $supplier->name, max(0.5, $cost), 'Seeded restock', $supplier->id);
                Carbon::setTestNow();
            }
            if ($recent) {
                Carbon::setTestNow(now()->startOfDay()->subDays(mt_rand(2, 28))->setTime(2, 30));
                $inventory->restock($variant, mt_rand(4, 12), $admin, $supplier->name, max(0.5, round((float) $variant->price_usd * $margin, 2)), 'Seeded restock', $supplier->id);
                Carbon::setTestNow();
            }
        }
    }

    /**
     * A handful of returns in every state, so the Returns page has real cases: older ones refunded or not accepted,
     * recent ones waiting for a decision or a refund. Each is asked two days after its order arrived.
     */
    private function returns(array $customers, StaffUser $admin): void
    {
        $service = app(ReturnService::class);
        $reasons = [
            ['Two pages were printed upside down.', 'Printing fault'],
            ['The cover arrived bent and the corner is torn.', 'Damaged in delivery'],
            ['I ordered the paperback but received the hardcover.', 'Wrong format'],
            ['The book has water damage on the bottom edge.', 'Water damage'],
            ['I bought it twice by mistake.', 'Ordered twice'],
            ['Some pages are missing in the middle (pages 120 to 136).', 'Missing pages'],
            ['The spine cracked the first time I opened it.', 'Spine broken'],
            ['Not what I expected from the description.', 'Changed my mind'],
        ];
        // [days ago it was delivered at least, outcome]
        $plan = [[150, 'refunded'], [120, 'refunded'], [95, 'rejected'], [70, 'refunded'], [40, 'rejected'], [12, 'approved'], [6, 'approved'], [3, 'requested'], [2, 'requested']];
        foreach ($plan as $i => [$ago, $outcome]) {
            $order = Order::query()->whereIn('customer_id', collect($customers)->pluck('id'))->where('status', 'delivered')
                ->whereHas('statusHistory', fn ($q) => $q->where('status', 'delivered')->where('created_at', '<=', now()->subDays($ago)))
                ->whereDoesntHave('returns')->orderByDesc('id')->first();
            if (! $order) {
                continue;
            }
            try {
                $asked = $service->deliveredAt($order)->copy()->addDays(2);
                Carbon::setTestNow($asked->isFuture() ? now()->subHours(3) : $asked);
                $item = $order->items()->first();
                [$why, $short] = $reasons[$i % count($reasons)];
                $return = $service->request($order->customer, $order, $why, [['order_item_id' => $item->id, 'quantity' => 1, 'reason' => $short]]);
                Carbon::setTestNow(now()->addDay()->isFuture() ? now()->addMinutes(30) : now()->addDay());
                match ($outcome) {
                    'approved' => $service->approve($return, $admin, 'Please bring the book to the shop or hand it to the courier.'),
                    'rejected' => $service->reject($return, $admin, $short === 'Changed my mind' ? 'Books that were read can only be returned when faulty.' : 'The photos show the book in good condition, so we cannot accept this return.'),
                    'refunded' => $service->refund($service->approve($return, $admin, null), $admin, $i % 2 ? 'cash' : 'bank_transfer', $i % 2 ? null : 'ABA-'.(400100 + $i)),
                    default => null,
                };
            } catch (Throwable $e) {
                $this->command?->warn('Skipped a seeded return: '.$e->getMessage());
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    /** ~2 orders a day through the real checkout; the last days stay open so the order list has work in it. */
    private function orders(array $customers, $variants, StaffUser $admin): void
    {
        $cart = app(CartService::class);
        $checkout = app(CheckoutService::class);
        $status = app(OrderStatusService::class);
        $count = 0;
        for ($daysAgo = self::DAYS - 10; $daysAgo >= 0; $daysAgo--) {
            // Busier at weekends and towards the present, quieter mid-week.
            $base = $daysAgo < 60 ? 2 : 1;
            $perDay = $base + (now()->subDays($daysAgo)->isWeekend() ? 1 : 0) + (mt_rand(0, 3) === 0 ? 1 : 0) - (mt_rand(0, 4) === 0 ? 1 : 0);
            for ($k = 0; $k < max(0, $perDay); $k++) {
                $customer = $customers[mt_rand(0, count($customers) - 1)];
                Carbon::setTestNow(now()->startOfDay()->subDays($daysAgo)->setTime(mt_rand(1, 13), mt_rand(0, 59)));
                try {
                    DB::transaction(function () use ($cart, $checkout, $status, $customer, $variants, $admin, $daysAgo, &$count) {
                        $lines = mt_rand(1, 10) <= 6 ? 1 : (mt_rand(1, 10) <= 7 ? 2 : 3);
                        foreach ($variants->random(min($lines, $variants->count())) as $variant) {
                            if ($variant->fresh()->stock_quantity < 1) {
                                continue;
                            }
                            $cart->add($customer, $variant->id, mt_rand(1, 10) === 1 ? 2 : 1);
                        }
                        $method = mt_rand(1, 10) <= 6 ? PaymentMethod::Cod : PaymentMethod::Khqr;
                        $order = $checkout->place($customer, $customer->addresses()->value('id'), null, 'activity-seed-'.$daysAgo.'-'.$customer->id.'-'.mt_rand(), paymentMethod: $method)['order'];
                        $final = match (true) {
                            $daysAgo <= 1 => OrderStatus::Pending,
                            $daysAgo <= 3 => mt_rand(0, 1) ? OrderStatus::Processing : OrderStatus::Shipped,
                            mt_rand(1, 100) <= 7 => OrderStatus::Cancelled,
                            default => OrderStatus::Delivered,
                        };
                        $steps = match ($final) {
                            OrderStatus::Delivered => [OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered],
                            OrderStatus::Shipped => [OrderStatus::Processing, OrderStatus::Shipped],
                            OrderStatus::Processing => [OrderStatus::Processing],
                            OrderStatus::Cancelled => [OrderStatus::Cancelled],
                            default => [],
                        };
                        foreach ($steps as $i => $step) {
                            Carbon::setTestNow(now()->addHours($i === 2 ? 26 : 4));
                            $status->changeByStaff($order, $step, $admin, $step === OrderStatus::Cancelled ? 'Customer changed their mind.' : null);
                        }
                        $count++;
                    });
                } catch (Throwable $e) {
                    // An empty cart or a sold-out pick just skips that order, like a visitor leaving.
                    $cart->clear($customer);
                }
                Carbon::setTestNow();
            }
        }
        // Reports keep answers for 5 minutes; drop them so the new numbers show at once.
        Artisan::call('cache:clear');
        $this->command?->info("Seeded {$count} orders, ".count(self::SUPPLIERS).' suppliers and dated restocks with costs.');
    }
}
