<?php

namespace Database\Seeders;

use App\Enums\BookFormat;
use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\Publisher;
use App\Models\Series;
use App\Models\StaffUser;
use App\Services\Cart\CartService;
use App\Services\Orders\CheckoutService;
use App\Services\Orders\OrderStatusService;
use App\Services\Returns\ReturnService;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sample shop for local development and portfolio demos. Never runs automatically:
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Orders are placed through the real cart, checkout and status services (with the clock moved back),
 * so stock movements, order history, payments and the dashboard all look like real use.
 * All demo passwords: Password123!
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder does not run in production.');

            return;
        }
        if (Customer::where('email', 'demo@bookly.test')->exists()) {
            $this->command?->warn('Demo data is already there (demo@bookly.test exists). Run migrate:fresh first to start over.');

            return;
        }

        // Send the order emails nowhere and run queued work straight away while seeding.
        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        $admin = StaffUser::create(['name' => 'Demo Admin', 'email' => 'admin@bookly.test', 'password_hash' => self::PASSWORD, 'role' => StaffRole::Admin]);
        StaffUser::create(['name' => 'Demo Staff', 'email' => 'staff@bookly.test', 'password_hash' => self::PASSWORD, 'role' => StaffRole::Staff]);

        ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'KHR', 'rate' => '4100', 'effective_at' => now()->subMonths(2)]);

        Coupon::create(['code' => 'WELCOME10', 'type' => CouponType::Percentage, 'value' => '10.00', 'min_order_amount' => '20.00', 'is_active' => true]);
        Coupon::create(['code' => 'SAVE5', 'type' => CouponType::Fixed, 'value' => '5.00', 'min_order_amount' => '30.00', 'max_uses' => 100, 'is_active' => true]);

        $variants = $this->catalog();
        $customers = $this->customers();

        $this->orders($customers, $variants, $admin);
    }

    /** @return array<string, \App\Models\BookVariant> paperback/hardcover/ebook variants keyed "Title|format" */
    private function catalog(): array
    {
        $categories = collect(['Fiction', 'Classics', 'Mystery', 'Science Fiction', 'Fantasy', 'Adventure', 'Philosophy', 'Poetry'])
            ->mapWithKeys(fn ($name) => [$name => Category::create(['name' => $name, 'slug' => Str::slug($name)])]);

        $publishers = collect(['Mekong Press', 'Angkor House', 'Riverside Classics'])
            ->mapWithKeys(fn ($name) => [$name => Publisher::create(['name' => $name])]);

        $holmes = Series::create(['name' => 'Sherlock Holmes', 'description' => 'The detective novels of Arthur Conan Doyle.']);
        $verne = Series::create(['name' => 'Extraordinary Voyages', 'description' => 'Jules Verne\'s adventure novels.']);

        // [title, author, categories, publisher, year, pages, series, series order, paperback price, stock, extra formats]
        $books = [
            ['Pride and Prejudice', 'Jane Austen', ['Fiction', 'Classics'], 'Riverside Classics', 1813, 432, null, null, '12.99', 40, ['hardcover', 'ebook']],
            ['Emma', 'Jane Austen', ['Fiction', 'Classics'], 'Riverside Classics', 1815, 474, null, null, '11.99', 25, ['ebook']],
            ['Frankenstein', 'Mary Shelley', ['Classics', 'Science Fiction'], 'Mekong Press', 1818, 280, null, null, '9.99', 30, ['ebook']],
            ['A Study in Scarlet', 'Arthur Conan Doyle', ['Mystery', 'Classics'], 'Angkor House', 1887, 188, $holmes, 1, '8.99', 35, ['ebook']],
            ['The Sign of the Four', 'Arthur Conan Doyle', ['Mystery', 'Classics'], 'Angkor House', 1890, 166, $holmes, 2, '8.99', 20, ['ebook']],
            ['The Hound of the Baskervilles', 'Arthur Conan Doyle', ['Mystery', 'Classics'], 'Angkor House', 1902, 256, $holmes, 3, '10.99', 4, ['hardcover', 'ebook']],
            ['Twenty Thousand Leagues Under the Seas', 'Jules Verne', ['Adventure', 'Science Fiction'], 'Mekong Press', 1870, 426, $verne, 1, '13.50', 18, ['ebook']],
            ['Around the World in Eighty Days', 'Jules Verne', ['Adventure', 'Classics'], 'Mekong Press', 1872, 224, $verne, 2, '10.50', 22, ['audiobook']],
            ['The Mysterious Island', 'Jules Verne', ['Adventure', 'Science Fiction'], 'Mekong Press', 1875, 560, $verne, 3, '14.00', 0, ['ebook']],
            ['The Time Machine', 'H. G. Wells', ['Science Fiction', 'Classics'], 'Riverside Classics', 1895, 118, null, null, '7.99', 45, ['ebook', 'audiobook']],
            ['The War of the Worlds', 'H. G. Wells', ['Science Fiction', 'Classics'], 'Riverside Classics', 1898, 192, null, null, '9.50', 15, ['ebook']],
            ['Treasure Island', 'Robert Louis Stevenson', ['Adventure', 'Fiction'], 'Angkor House', 1883, 292, null, null, '9.99', 28, ['hardcover']],
            ['Alice\'s Adventures in Wonderland', 'Lewis Carroll', ['Fantasy', 'Classics'], 'Mekong Press', 1865, 192, null, null, '8.50', 33, ['hardcover', 'ebook']],
            ['The Picture of Dorian Gray', 'Oscar Wilde', ['Fiction', 'Classics'], 'Riverside Classics', 1890, 254, null, null, '10.99', 3, ['ebook']],
            ['Dracula', 'Bram Stoker', ['Fiction', 'Classics'], 'Angkor House', 1897, 418, null, null, '11.50', 26, ['ebook']],
            ['Moby-Dick', 'Herman Melville', ['Adventure', 'Classics'], 'Riverside Classics', 1851, 635, null, null, '15.99', 12, ['hardcover']],
            ['The Adventures of Tom Sawyer', 'Mark Twain', ['Adventure', 'Fiction'], 'Mekong Press', 1876, 274, null, null, '8.99', 30, ['ebook']],
            ['Meditations', 'Marcus Aurelius', ['Philosophy'], 'Angkor House', 180, 254, null, null, '9.99', 40, ['ebook', 'audiobook']],
            ['The Art of War', 'Sun Tzu', ['Philosophy'], 'Mekong Press', -500, 112, null, null, '6.99', 50, ['ebook']],
            ['Leaves of Grass', 'Walt Whitman', ['Poetry', 'Classics'], 'Riverside Classics', 1855, 400, null, null, '12.50', 10, ['hardcover']],
        ];

        $authors = [];
        $variants = [];

        foreach ($books as $i => [$title, $authorName, $categoryNames, $publisherName, $year, $pages, $series, $order, $price, $stock, $extra]) {
            $authors[$authorName] ??= Author::create(['name' => $authorName, 'bio' => "{$authorName} is one of the classic authors in the Bookly catalog."]);

            $book = Book::create([
                'title' => $title,
                'description' => "{$title} by {$authorName}, a classic first published in ".($year < 0 ? abs($year).' BC' : $year).'.',
                'publisher_id' => $publishers[$publisherName]->id,
                'series_id' => $series?->id,
                'series_order' => $order,
                'language' => 'English',
                'page_count' => $pages,
                // Old books: show the date of this edition (spread over the last two years) rather than 1813.
                'publish_date' => now()->subDays(20 + $i * 35)->toDateString(),
            ]);
            $book->authors()->attach($authors[$authorName]);
            $book->categories()->attach($categories->only($categoryNames)->pluck('id'));

            $cover = 'https://picsum.photos/seed/bookly-'.($i + 1).'/400/600';
            $variants["{$title}|paperback"] = $book->variants()->create([
                'format' => BookFormat::Paperback, 'isbn' => $this->isbn($i, 0), 'sku' => sprintf('BK%03d-PB', $i + 1),
                'price_usd' => $price, 'stock_quantity' => $stock, 'low_stock_threshold' => 5, 'cover_image_url' => $cover,
            ]);

            foreach ($extra as $format) {
                [$price2, $stock2, $code] = match ($format) {
                    'hardcover' => [Money::format(Money::toCents($price) + 800), max(2, intdiv($stock, 3)), 'HC'],
                    'ebook' => [Money::format(Money::toCents($price) - 400), 0, 'EB'],
                    'audiobook' => [Money::format(Money::toCents($price) + 500), 0, 'AU'],
                };
                $variants["{$title}|{$format}"] = $book->variants()->create([
                    'format' => BookFormat::from($format), 'isbn' => $this->isbn($i, $code === 'HC' ? 1 : ($code === 'EB' ? 2 : 3)),
                    'sku' => sprintf('BK%03d-%s', $i + 1, $code), 'price_usd' => $price2, 'stock_quantity' => $stock2,
                    'low_stock_threshold' => $format === 'hardcover' ? 2 : 0, 'cover_image_url' => $cover,
                ]);
            }
        }

        return $variants;
    }

    /** A valid ISBN-13 that is unique per book and format. */
    private function isbn(int $book, int $format): string
    {
        $digits = sprintf('979%02d%07d', 10 + $format, 1000 + $book);
        $sum = 0;
        foreach (str_split($digits) as $pos => $digit) {
            $sum += (int) $digit * ($pos % 2 === 0 ? 1 : 3);
        }

        return $digits.((10 - $sum % 10) % 10);
    }

    /** @return array<string, Customer> */
    private function customers(): array
    {
        $people = [
            'demo' => ['Demo Customer', 'demo@bookly.test', 'Street 240, House 12', 'Phnom Penh'],
            'sokha' => ['Sokha Chan', 'sokha@bookly.test', 'Street 63, House 5B', 'Phnom Penh'],
            'dara' => ['Dara Kim', 'dara@bookly.test', 'Sivutha Blvd 88', 'Siem Reap'],
            'malis' => ['Malis Heng', 'malis@bookly.test', 'Street 7 Makara, House 21', 'Battambang'],
            'vicheka' => ['Vicheka Lim', 'vicheka@bookly.test', 'Ou Chheuteal Beach Rd 3', 'Sihanoukville'],
        ];

        $customers = [];
        foreach ($people as $key => [$name, $email, $street, $city]) {
            $customer = Customer::create([
                'name' => $name, 'email' => $email, 'password_hash' => self::PASSWORD,
                'phone' => '012'.str_pad((string) (345600 + count($customers)), 6, '0', STR_PAD_LEFT), 'email_verified_at' => now()->subMonths(2),
            ]);
            $customer->forceFill(['created_at' => now()->subDays(40 - count($customers) * 8)])->save();
            $customer->addresses()->create([
                'label' => 'Home', 'recipient_name' => $name, 'phone' => $customer->phone,
                'address_line1' => $street, 'city' => $city, 'country' => 'Cambodia', 'is_default' => true,
            ]);
            $customers[$key] = $customer;
        }

        return $customers;
    }

    private function orders(array $customers, array $variants, StaffUser $admin): void
    {
        $cart = app(CartService::class);
        $checkout = app(CheckoutService::class);
        $status = app(OrderStatusService::class);

        // [customer, days ago, lines [title|format => qty], coupon, final status]
        $plan = [
            ['sokha', 28, ['Pride and Prejudice|paperback' => 1, 'Emma|paperback' => 1], null, OrderStatus::Delivered],
            ['dara', 26, ['A Study in Scarlet|paperback' => 1, 'The Sign of the Four|paperback' => 1, 'The Hound of the Baskervilles|paperback' => 1], 'WELCOME10', OrderStatus::Delivered],
            ['malis', 23, ['The Time Machine|ebook' => 1], null, OrderStatus::Delivered],
            ['demo', 20, ['Treasure Island|paperback' => 2, 'Meditations|paperback' => 1], null, OrderStatus::Delivered],
            ['vicheka', 17, ['Moby-Dick|hardcover' => 1, 'Dracula|paperback' => 1], 'SAVE5', OrderStatus::Delivered],
            ['sokha', 14, ['Frankenstein|paperback' => 1, 'The War of the Worlds|paperback' => 1], null, OrderStatus::Delivered],
            ['dara', 11, ['Around the World in Eighty Days|paperback' => 1], null, OrderStatus::Cancelled],
            ['demo', 8, ['The Hound of the Baskervilles|hardcover' => 1, 'Alice\'s Adventures in Wonderland|paperback' => 1], null, OrderStatus::Delivered],
            ['malis', 6, ['The Art of War|paperback' => 3], null, OrderStatus::Delivered],
            ['vicheka', 4, ['Twenty Thousand Leagues Under the Seas|paperback' => 1, 'The Adventures of Tom Sawyer|paperback' => 1], null, OrderStatus::Shipped],
            ['sokha', 2, ['The Picture of Dorian Gray|paperback' => 1], null, OrderStatus::Processing],
            ['demo', 1, ['Leaves of Grass|paperback' => 1, 'Pride and Prejudice|ebook' => 1], null, OrderStatus::Pending],
            ['dara', 0, ['Meditations|audiobook' => 1, 'The Time Machine|paperback' => 1], null, OrderStatus::Pending],
        ];

        $orders = [];
        try {
            foreach ($plan as $n => [$who, $daysAgo, $lines, $coupon, $final]) {
                $customer = $customers[$who];
                Carbon::setTestNow(now()->startOfDay()->subDays($daysAgo)->setTime(3 + $n % 6, 15)); // 10:15-15:15 Phnom Penh

                foreach ($lines as $key => $qty) {
                    $cart->add($customer, $variants[$key]->id, $qty);
                }
                $order = $checkout->place($customer, $customer->addresses()->value('id'), $coupon, 'demo-seed-'.($n + 1))['order'];

                // Walk the order forward like the staff would, a little later each step.
                $steps = match ($final) {
                    OrderStatus::Delivered => [OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered],
                    OrderStatus::Shipped => [OrderStatus::Processing, OrderStatus::Shipped],
                    OrderStatus::Processing => [OrderStatus::Processing],
                    OrderStatus::Cancelled => [OrderStatus::Cancelled],
                    default => [],
                };
                foreach ($steps as $s => $step) {
                    Carbon::setTestNow(now()->addHours($s === 2 ? 30 : 5));
                    $status->changeByStaff($order, $step, $admin, $step === OrderStatus::Cancelled ? 'Customer asked to cancel by phone.' : null);
                }

                $orders[] = $order->fresh();
                Carbon::setTestNow();
            }

            $this->reviewsAndReturns($orders, $customers, $admin);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @param  list<Order>  $orders */
    private function reviewsAndReturns(array $orders, array $customers, StaffUser $admin): void
    {
        $reviews = [
            [0, 'Pride and Prejudice', 5, 'A joy to reread. Nice paper and clear print.'],
            [0, 'Emma', 4, 'Slow start but worth it.'],
            [1, 'The Hound of the Baskervilles', 5, 'The best Holmes story. Arrived well packed.'],
            [1, 'A Study in Scarlet', 4, null],
            [3, 'Treasure Island', 5, 'Bought one for my son as well. We both loved it.'],
            [5, 'Frankenstein', 3, 'Good book, the cover was slightly bent on arrival.'],
        ];
        foreach ($reviews as [$o, $title, $rating, $comment]) {
            $item = $orders[$o]->items()->whereHas('variant.book', fn ($q) => $q->where('title', $title))->firstOrFail();
            $orders[$o]->customer->reviews()->create([
                'book_id' => $item->variant->book_id, 'order_item_id' => $item->id, 'rating' => $rating, 'comment' => $comment,
            ]);
        }

        // One open return for the staff to handle: Malis received a damaged copy.
        $returns = app(ReturnService::class);
        $order = $orders[8];
        $returns->request($customers['malis'], $order, 'One copy arrived with torn pages.', [
            ['order_item_id' => $order->items()->value('id'), 'quantity' => 1, 'reason' => 'Torn pages'],
        ]);
    }
}
