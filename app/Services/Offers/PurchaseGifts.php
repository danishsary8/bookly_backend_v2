<?php

namespace App\Services\Offers;

use App\Models\Gift;
use App\Models\Offer;
use App\Models\Order;
use App\Models\StaffUser;
use App\Notifications\LowGiftStockNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Gift inventory is never cached. Checkout locks candidates in id order and rechecks under the lock. */
class PurchaseGifts
{
    public function select(array $summary, bool $reserve = false): ?array
    {
        $query = Offer::query()->where('type', 'gift')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_orders')->orWhereColumn('used_count', '<', 'max_orders'))
            ->with('targets')->orderBy('id');
        if ($reserve) {
            $query->lockForUpdate();
        }
        foreach ($query->get() as $offer) {
            $lines = $summary['lines']->filter(function ($line) use ($offer) {
                if ($line['blocked']) {
                    return false;
                }
                $book = $line['item']->variant->book;

                return $book && ($offer->applies_to === 'all' || $offer->targets->contains(fn ($target) => ! $target->inTrash() && (
                    $target->book_id === $book->id || ($target->category_id !== null && $book->categories->contains('id', $target->category_id))
                    || ($target->series_id !== null && $target->series_id === $book->series_id)
                )));
            });
            $eligible = $offer->min_subtotal !== null
                ? $lines->sum('line_total_cents') >= Money::toCents($offer->min_subtotal) && $lines->isNotEmpty()
                : $lines->sum(fn ($line) => $line['item']->quantity) >= $offer->min_quantity;
            if (! $eligible) {
                continue;
            }
            $giftQuery = Gift::whereKey($offer->gift_id);
            $gift = ($reserve ? $giftQuery->lockForUpdate() : $giftQuery)->first();
            if (! $gift || ! $gift->is_active || $gift->stock < $offer->gift_quantity) {
                continue;
            }
            if ($reserve) {
                $gift->decrement('stock', $offer->gift_quantity);
                $offer->increment('used_count');
                if ($gift->stock <= 5) {
                    DB::afterCommit(fn () => Notification::send(
                        StaffUser::all(), new LowGiftStockNotification($gift),
                    ));
                }
            }

            return ['id' => $gift->id, 'offer_id' => $offer->id, 'name_en' => $gift->name_en, 'name_km' => $gift->name_km,
                'quantity' => $offer->gift_quantity, 'limited' => true];
        }

        return null;
    }

    public function restore(Order $order): void
    {
        $gift = $order->free_gift;
        if ($gift === null) {
            return;
        }
        Offer::whereKey($gift['offer_id'])->lockForUpdate()->first();
        Gift::whereKey($gift['id'])->lockForUpdate()->first()?->increment('stock', $gift['quantity']);
        Offer::whereKey($gift['offer_id'])->where('used_count', '>', 0)->decrement('used_count');
    }
}
