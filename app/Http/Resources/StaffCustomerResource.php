<?php

namespace App\Http\Resources;

use App\Services\Customers\ClosedCustomers;
use App\Services\Customers\UnverifiedCustomers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;
        $stats = $this->stats;
        if (is_array($stats) && ! $isAdmin) {
            unset($stats['lifetime_spent_usd']);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_number' => $this->phone_e164,
            'phone_verified' => $this->hasVerifiedPhone(),
            'email_verified' => $this->email_verified_at !== null,
            'verified' => $this->isVerified(),
            'is_active' => $this->is_active,
            'tags' => $this->relationLoaded('tags') ? $this->tags->pluck('name')->values() : [],
            'login_methods' => array_values(array_filter([
                $this->password_hash !== null ? 'password' : null,
                $this->google_id !== null ? 'google' : null,
                $this->facebook_id !== null ? 'facebook' : null,
            ])),
            'orders_count' => $this->whenCounted('orders'),
            'stats' => $this->when(isset($this->stats), fn () => $stats),
            'recent_orders' => $this->when(isset($this->recentOrders), fn () => $this->recentOrders->map(fn ($o) => array_filter([
                'id' => $o->id,
                'order_number' => $o->order_number,
                'status' => $o->status->value,
                'placed_at' => $o->placed_at,
                'total_usd' => $isAdmin ? $o->total_amount : null,
            ], fn ($v) => $v !== null))->values()),
            'created_at' => $this->created_at,
            // Unfinished sign-ups are deleted at this time unless the customer enters their code first.
            'removal_at' => $this->trashed() ? null : UnverifiedCustomers::removalAt($this->resource),
            // Closed by the customer: their details are erased at erase_at; until then an admin can reopen it.
            'closed_at' => $this->deleted_at,
            'erase_at' => ClosedCustomers::eraseAt($this->resource),
        ];
    }
}
