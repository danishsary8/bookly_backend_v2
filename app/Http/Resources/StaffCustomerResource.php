<?php

namespace App\Http\Resources;

use App\Services\Customers\UnverifiedCustomers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verified' => $this->email_verified_at !== null,
            'is_active' => $this->is_active,
            'login_methods' => array_values(array_filter([
                $this->password_hash !== null ? 'password' : null,
                $this->google_id !== null ? 'google' : null,
                $this->facebook_id !== null ? 'facebook' : null,
            ])),
            'orders_count' => $this->whenCounted('orders'),
            'stats' => $this->when(isset($this->stats), fn () => $this->stats),
            'recent_orders' => $this->when(isset($this->recentOrders), fn () => OrderResource::collection($this->recentOrders)),
            'created_at' => $this->created_at,
            // Unfinished sign-ups are deleted at this time unless the customer enters their code first.
            'removal_at' => UnverifiedCustomers::removalAt($this->resource),
        ];
    }
}
