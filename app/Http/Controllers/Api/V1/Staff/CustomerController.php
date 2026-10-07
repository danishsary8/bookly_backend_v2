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
use Illuminate\Http\JsonResponse;
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
                ->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term)->orWhere('phone', 'ilike', $term)->orWhere('phone_e164', 'ilike', $term)))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')));

        return StaffCustomerResource::collection(
            (clone $base)
                ->withCount('orders')
                ->when($request->has('verified'), fn ($q) => $request->boolean('verified')
                    ? $q->verified() : $q->unverified())
                ->latest()
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        )->additional(['meta' => ['counts' => [
            'verified' => (clone $base)->verified()->count(),
            'unverified' => (clone $base)->unverified()->count(),
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

    /**
     * Admins: delete an unfinished sign-up now instead of waiting for the 48-hour clean-up, so its email can be
     * used again. Verified customers, or anything with orders, returns, reviews or addresses, can't be deleted.
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->unverified->deletable($customer)) {
            return response()->json(['message' => $customer->isVerified()
                ? 'Only unfinished sign-ups can be deleted. Verified customers can be deactivated instead.'
                : 'This account has orders, returns, reviews or addresses, so it can\'t be deleted.'], 422);
        }

        $this->audit->custom($request->user(), 'deleted', $customer, ['email' => $customer->email, 'phone' => $customer->phone, 'name' => $customer->name, 'verified' => false], null);
        $this->unverified->delete($customer);

        return response()->json(null, 204);
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
