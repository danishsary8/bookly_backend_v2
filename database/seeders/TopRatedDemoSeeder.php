<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Book;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Local mock reviews only; independent of the one-time DemoSeeder. */
class TopRatedDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('TopRatedDemoSeeder is restricted to local and testing environments.');
        }

        $profiles = json_decode(file_get_contents(__DIR__.'/data/top_rated_reviews.json'), true, 512, JSON_THROW_ON_ERROR);
        $comments = [
            'An engaging read with memorable characters.',
            'Enjoyed the writing and would recommend it to a friend.',
            'A thoughtful book that gave me plenty to reflect on.',
            'A strong addition to my reading shelf.',
        ];

        DB::transaction(function () use ($profiles, $comments): void {
            foreach ($profiles as $profile) {
                $book = Book::where('title', $profile['title'])->orderBy('id')->first();
                $variant = $book?->variants()->orderBy('id')->first();
                if (! $variant) {
                    $this->command?->warn('Skipped missing catalog book: '.$profile['title']);

                    continue;
                }

                foreach ($profile['ratings'] as $index => $rating) {
                    $customer = Customer::firstOrCreate(['email' => 'mock-reader-'.($index + 1).'@bookly.test'], [
                        'name' => 'Demo Reader '.($index + 1),
                        'password_hash' => Str::random(40),
                        'email_verified_at' => now(),
                    ]);
                    // Synthetic delivered orders satisfy the review schema; no real checkout or stock changes.
                    $order = Order::firstOrCreate(['order_number' => 'MOCK-REVIEW-'.$book->id.'-'.($index + 1)], [
                        'customer_id' => $customer->id,
                        'shipping_recipient_name' => $customer->name,
                        'shipping_phone' => '012345678',
                        'shipping_address_line1' => 'Demo address',
                        'shipping_city' => 'Phnom Penh',
                        'shipping_country' => 'Cambodia',
                        'status' => OrderStatus::Delivered,
                        'payment_method' => PaymentMethod::Cod,
                        'subtotal' => $variant->price_usd,
                        'total_amount' => $variant->price_usd,
                        'currency' => 'USD',
                        'placed_at' => now()->subDays(14),
                        'staff_note' => 'Synthetic review fixture. Not a real purchase.',
                    ]);
                    $item = $order->items()->firstOrCreate(['book_variant_id' => $variant->id], [
                        ...OrderItem::detailsOf($variant),
                        'quantity' => 1,
                        'unit_price' => $variant->price_usd,
                        'subtotal' => $variant->price_usd,
                    ]);
                    Review::firstOrCreate(['book_id' => $book->id, 'customer_id' => $customer->id], [
                        'order_item_id' => $item->id,
                        'rating' => $rating,
                        'comment' => '[Demo review] '.$comments[$index % count($comments)],
                        'is_approved' => true,
                    ]);
                }
            }
        });
    }
}
