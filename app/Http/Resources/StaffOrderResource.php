<?php

namespace App\Http\Resources;

use App\Services\Orders\OrderStatusService;
use Illuminate\Http\Request;

class StaffOrderResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
            ]),
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'status' => $h->status->value,
                'note' => $h->note,
                'changed_by' => $h->changedBy ? ['id' => $h->changedBy->id, 'name' => $h->changedBy->name] : null,
                'created_at' => $h->created_at,
            ])->values()),
            'allowed_next_statuses' => collect($this->status->allowedNext())
                ->filter(fn ($s) => in_array($s, OrderStatusService::STAFF_TARGETS, true))
                ->map->value->values(),
        ];
    }
}
