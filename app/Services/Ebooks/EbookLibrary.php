<?php

namespace App\Services\Ebooks;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may read which ebook. A customer owns a book's ebook through an order containing its ebook format that has been
 * paid (KHQR) or confirmed by staff (cash on delivery): paid, processing, shipped or delivered. Derived from orders,
 * so a cancelled or returned order takes the ebook away by itself.
 */
class EbookLibrary
{
    /** @return list<string> */
    public static function owningStatuses(): array
    {
        return [OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Shipped->value, OrderStatus::Delivered->value];
    }

    /** The earliest qualifying order for the book (its number goes in the watermark), or null. */
    public function owningOrder(Customer $customer, int $bookId): ?Order
    {
        return $this->ordersWithEbooks($customer)
            ->whereHas('items.variant', fn (Builder $q) => $q->where('book_id', $bookId)->where('format', BookFormat::Ebook->value))
            ->orderBy('placed_at')
            ->first();
    }

    /** @return Collection<int, int> book ids the customer owns as ebooks */
    public function ownedBookIds(Customer $customer): Collection
    {
        return $this->ordersWithEbooks($customer)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->where('book_variants.format', BookFormat::Ebook->value)
            ->distinct()
            ->pluck('book_variants.book_id')
            ->map(fn ($id) => (int) $id);
    }

    private function ordersWithEbooks(Customer $customer): Builder
    {
        return Order::query()->where('orders.customer_id', $customer->id)->whereIn('orders.status', self::owningStatuses());
    }
}
