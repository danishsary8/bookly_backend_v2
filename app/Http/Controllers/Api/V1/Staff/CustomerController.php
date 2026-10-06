<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffCustomerResource;
use App\Models\Customer;
use App\Services\Admin\AuditLogger;
use App\Services\Customers\UnverifiedCustomers;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly UnverifiedCustomers $unverified) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'active' => ['nullable', 'boolean'],
            'verified' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $term = isset($data['q']) ? '%'.addcslashes($data['q'], '%_').'%' : null;
        $this->unverified->pruneIfDue();

        // Search and active filters, without the verified filter: the tabs show both counts.
        $base = Customer::query()
            ->when($term, fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term)->orWhere('phone', 'ilike', $term)))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')));

        return StaffCustomerResource::collection(
            (clone $base)
                ->withCount('orders')
                ->when($request->has('verified'), fn ($q) => $request->boolean('verified')
                    ? $q->whereNotNull('email_verified_at') : $q->whereNull('email_verified_at'))
                ->latest()
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        )->additional(['meta' => ['counts' => [
            'verified' => (clone $base)->whereNotNull('email_verified_at')->count(),
            'unverified' => (clone $base)->whereNull('email_verified_at')->count(),
        ]]]);
    }

    public function show(Customer $customer): StaffCustomerResource
    {
        $paid = $customer->orders()->whereIn('status', [OrderStatus::Delivered, OrderStatus::Returned])->sum('total_amount');
        $refunded = $customer->returns()->where('status', ReturnStatus::Refunded)->sum('refund_amount');

        $customer->loadCount('orders');
        $customer->stats = [
            'orders_by_status' => $customer->orders()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n),
            'returns_count' => $customer->returns()->count(),
            'reviews_count' => $customer->reviews()->count(),
            // Money actually kept: delivered orders minus refunds.
            'lifetime_spent_usd' => Money::format(Money::toCents($paid) - Money::toCents($refunded)),
            'last_order_at' => $customer->orders()->max('placed_at'),
        ];
        $customer->recentOrders = $customer->orders()
            ->with(['items.variant.book' => fn ($q) => $q->withTrashed(), 'payments', 'coupon'])
            ->latest('placed_at')->limit(5)->get();

        return new StaffCustomerResource($customer);
    }

    public function deactivate(Request $request, Customer $customer): StaffCustomerResource
    {
        return $this->setActive($request, $customer, false);
    }

    public function activate(Request $request, Customer $customer): StaffCustomerResource
    {
        return $this->setActive($request, $customer, true);
    }

    private function setActive(Request $request, Customer $customer, bool $active): StaffCustomerResource
    {
        if ($customer->is_active !== $active) {
            $customer->update(['is_active' => $active]);
            if (! $active) {
                $customer->tokens()->delete();
            }
            $this->audit->custom($request->user(), $active ? 'activated' : 'deactivated', $customer, ['is_active' => ! $active], ['is_active' => $active]);
        }

        return new StaffCustomerResource($customer->loadCount('orders'));
    }
}
