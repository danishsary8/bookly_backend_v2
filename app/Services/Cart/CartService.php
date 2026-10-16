<?php

namespace App\Services\Cart;

use App\Models\BookVariant;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartService
{
    public const MAX_PER_LINE = 10;

    /** Issue codes that stop checkout. `price_changed` is informational only. */
    public const BLOCKING_ISSUES = ['unavailable', 'out_of_stock', 'insufficient_stock'];

    public function cartFor(Customer $customer): Cart
    {
        return Cart::firstOrCreate(['customer_id' => $customer->id]);
    }

    /** Adding a format that is already in the cart increases its quantity. */
    public function add(Customer $customer, int $variantId, int $quantity): void
    {
        DB::transaction(function () use ($customer, $variantId, $quantity) {
            $cart = $this->lockedCart($customer);
            $variant = BookVariant::with(['book' => fn ($q) => $q->withTrashed()])->findOrFail($variantId);
            $line = $cart->items()->where('book_variant_id', $variantId)->first();

            $this->assertCanHold($variant, ($line?->quantity ?? 0) + $quantity, alreadyInCart: $line !== null);

            if ($line) {
                $line->update(['quantity' => $line->quantity + $quantity, 'unit_price_at_add' => $variant->price_usd]);
            } else {
                $cart->items()->create([
                    'book_variant_id' => $variantId,
                    'quantity' => $quantity,
                    'unit_price_at_add' => $variant->price_usd,
                ]);
            }
        });
    }

    public function setQuantity(Customer $customer, int $itemId, int $quantity): void
    {
        DB::transaction(function () use ($customer, $itemId, $quantity) {
            $line = $this->lockedCart($customer)->items()->with(['variant.book' => fn ($q) => $q->withTrashed()])->findOrFail($itemId);
            $this->assertCanHold($line->variant, $quantity);
            $line->update(['quantity' => $quantity]);
        });
    }

    public function remove(Customer $customer, int $itemId): void
    {
        $this->cartFor($customer)->items()->whereKey($itemId)->firstOrFail()->delete();
    }

    public function clear(Customer $customer): void
    {
        $this->cartFor($customer)->items()->delete();
    }

    /**
     * Current state of the cart. Prices are always today's prices; every line lists its issues
     * so the customer sees what changed instead of items disappearing.
     */
    public function summary(Customer $customer): array
    {
        $cart = $this->cartFor($customer)->load([
            'items' => fn ($q) => $q->orderBy('id'),
            'items.variant.book' => fn ($q) => $q->withTrashed(),
        ]);

        $lines = $cart->items->map(fn (CartItem $item) => $this->line($item));
        $purchasable = $lines->filter(fn ($line) => ! $line['blocked']);
        $subtotalCents = $purchasable->sum('line_total_cents');

        return [
            'cart' => $cart,
            'lines' => $lines,
            'item_count' => (int) $lines->sum(fn ($line) => $line['item']->quantity),
            'subtotal_cents' => $subtotalCents,
            'can_checkout' => $lines->isNotEmpty() && $lines->doesntContain('blocked', true),
        ];
    }

    private function line(CartItem $item): array
    {
        $variant = $item->variant;
        $issues = [];

        if (! $variant->is_active || $variant->book === null || $variant->book->trashed() || ! $variant->book->isPublic()) {
            $issues[] = ['code' => 'unavailable', 'message' => __('messages.item_unavailable')];
        } elseif (! $variant->format->isDigital() && $variant->stock_quantity === 0) {
            $issues[] = ['code' => 'out_of_stock', 'message' => __('messages.out_of_stock')];
        } elseif (! $variant->format->isDigital() && $item->quantity > $variant->stock_quantity) {
            $issues[] = [
                'code' => 'insufficient_stock',
                'message' => __('messages.insufficient_stock', ['count' => $variant->stock_quantity]),
                'available_quantity' => $variant->stock_quantity,
            ];
        }

        if ($item->unit_price_at_add !== null && Money::toCents($item->unit_price_at_add) !== Money::toCents($variant->price_usd)) {
            $issues[] = [
                'code' => 'price_changed',
                'message' => __('messages.price_changed', ['previous' => $item->unit_price_at_add, 'current' => $variant->price_usd]),
                'previous_price_usd' => $item->unit_price_at_add,
            ];
        }

        $blocked = collect($issues)->contains(fn ($issue) => in_array($issue['code'], self::BLOCKING_ISSUES, true));

        return [
            'item' => $item,
            'issues' => $issues,
            'blocked' => $blocked,
            'line_total_cents' => Money::toCents($variant->price_usd) * $item->quantity,
        ];
    }

    private function assertCanHold(BookVariant $variant, int $quantity, bool $alreadyInCart = false): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['quantity' => $message]);

        if (! $variant->is_active || $variant->book === null || $variant->book->trashed() || ! $variant->book->isPublic()) {
            throw ValidationException::withMessages(['book_variant_id' => __('messages.item_unavailable')]);
        }

        if ($variant->format->isDigital()) {
            if ($quantity > 1) {
                $fail($alreadyInCart
                    ? __('messages.digital_already_in_cart')
                    : __('messages.digital_single'));
            }

            return;
        }

        if ($quantity > self::MAX_PER_LINE) {
            $fail(__('messages.max_per_line', ['max' => self::MAX_PER_LINE]));
        }

        if ($variant->stock_quantity === 0) {
            $fail(__('messages.out_of_stock'));
        }

        if ($quantity > $variant->stock_quantity) {
            $fail(__('messages.insufficient_stock_simple', ['count' => $variant->stock_quantity]));
        }
    }

    /** Locks the customer's cart row so two quick requests cannot both add past the limits. */
    private function lockedCart(Customer $customer): Cart
    {
        $cart = $this->cartFor($customer);

        return Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
    }
}
