<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\StaffUser;
use App\Services\Cart\CartService;
use App\Services\Orders\CheckoutService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo reviews for books that have no rating yet, so the home page's Top 10 is fully rated. Never runs automatically:
 *
 *   php artisan db:seed --class=DemoReviewsSeeder
 *
 * Needs the DemoSeeder accounts (the four demo reviewers and an admin). Picks the unrated books in the order the
 * Top 10 lists them (rating sort, then newest first) until TARGET books have a score. A review must belong to a
 * delivered purchase, so each reviewer's books are bought through the real cart, checkout and status services with the
 * clock moved back (stock is taken like any sale), then reviewed: mostly 4-5 stars, some without a comment, because a
 * comment is optional. Safe to run again: once TARGET books have a score it does nothing.
 * Refuses to run in production without DEMO_SEED_ALLOWED=true.
 */
class DemoReviewsSeeder extends Seeder
{
    /** Books with a score after seeding (the home page shows ten; two spare). */
    public const TARGET = 12;

    /** Reviews per book. */
    private const PER_BOOK = 2;

    private const REVIEWERS = ['sokha@bookly.test', 'dara@bookly.test', 'malis@bookly.test', 'vicheka@bookly.test'];

    /** [stars, comment] in turn; null comments on purpose (a rating alone is a valid review). */
    private const REVIEWS = [
        [5, 'Could not put it down. Arrived quickly and well packed.'],
        [4, null],
        [5, 'A favourite now. Lovely edition.'],
        [4, 'Good read, a little slow in the middle.'],
        [5, null],
        [4, 'Bought it as a gift and ended up reading it first.'],
    ];

    public function run(): void
    {
        if (app()->isProduction() && ! config('app.demo_seed_allowed')) {
            $this->command?->error('DemoReviewsSeeder does not run in production. To fill a public demo site once, set DEMO_SEED_ALLOWED=true, run it, then remove the variable.');

            return;
        }

        $reviewers = Customer::whereIn('email', self::REVIEWERS)->whereHas('addresses')->orderBy('id')->get();
        $admin = StaffUser::where('role', StaffRole::Admin)->orderBy('id')->first();
        if ($reviewers->count() < self::PER_BOOK || ! $admin) {
            $this->command?->error('DemoReviewsSeeder needs the DemoSeeder accounts. Run DemoSeeder first.');

            return;
        }

        $need = self::TARGET - Book::visible()->where(fn (Builder $q) => $q->whereNotNull('reader_rating')->orWhereHas('reviews', fn (Builder $r) => $r->where('is_approved', true)))->count();
        if ($need <= 0) {
            $this->command?->info('Enough books already have a rating. Nothing to do.');

            return;
        }

        // The Top 10's order for books without a score: newest first.
        $books = Book::visible()->whereNull('reader_rating')->whereDoesntHave('reviews')->orderByDesc('id')->get()
            ->map(fn (Book $book) => [$book, $this->buyableVariant($book)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->take($need)
            ->values();

        // Book n goes to reviewers n, n+1 … so every reviewer buys a few books and no one reviews a book twice.
        $plan = [];
        foreach ($books as $n => [$book, $variant]) {
            for ($k = 0; $k < self::PER_BOOK; $k++) {
                $plan[$reviewers[($n + $k) % $reviewers->count()]->id][] = $variant;
            }
        }

        // Send the order emails nowhere and run queued work (order alerts included) straight away while seeding.
        config(['mail.default' => 'array', 'queue.default' => 'sync', 'services.ops_alerts.token' => null]);

        $cart = app(CartService::class);
        $checkout = app(CheckoutService::class);
        $status = app(OrderStatusService::class);
        $turn = 0;

        try {
            foreach ($reviewers->whereIn('id', array_keys($plan)) as $i => $customer) {
                Carbon::setTestNow(now()->startOfDay()->subDays(12 + $i * 3)->setTime(4 + $i, 20));
                $cart->clear($customer);
                foreach ($plan[$customer->id] as $variant) {
                    $cart->add($customer, $variant->id, 1);
                }
                $order = $checkout->place($customer, $customer->addresses()->value('id'), null, 'demo-reviews-'.Str::uuid())['order'];
                foreach ([OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered] as $s => $step) {
                    Carbon::setTestNow(now()->addHours($s === 2 ? 30 : 5));
                    $status->changeByStaff($order, $step, $admin);
                }

                Carbon::setTestNow(now()->addDays(2));
                foreach ($order->items()->with('variant')->get() as $item) {
                    [$rating, $comment] = self::REVIEWS[$turn++ % count(self::REVIEWS)];
                    $customer->reviews()->create(['book_id' => $item->variant->book_id, 'order_item_id' => $item->id, 'rating' => $rating, 'comment' => $comment]);
                }
                Carbon::setTestNow();
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info("Reviewed {$books->count()} book(s): ".$books->map(fn (array $pair) => $pair[0]->title)->join(', '));
    }

    /** A format a reviewer can buy now: a physical copy in stock (not a pre-order), else an ebook or audiobook. */
    private function buyableVariant(Book $book): ?BookVariant
    {
        $variants = $book->activeVariants()->orderBy('price_usd')->get();

        return $variants->first(fn (BookVariant $v) => ! $v->format->isDigital() && $v->stock_quantity > 0 && ! $v->isPreorder())
            ?? $variants->first(fn (BookVariant $v) => $v->format->isDigital() && ! $v->isPreorder());
    }
}
