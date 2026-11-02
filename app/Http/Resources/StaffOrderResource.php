<?php

namespace App\Http\Resources;

use App\Enums\PaymentProvider;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\Request;

class StaffOrderResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'staff_note' => $this->staff_note,
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
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->sortBy('id')->map(fn ($p) => [
                'id' => $p->id,
                'provider' => $p->provider->value,
                'status' => $p->status->value,
                'amount' => (string) $p->amount,
                'currency' => $p->currency,
                'reference' => $p->provider === PaymentProvider::Bakong ? $p->provider_transaction_id : null,
                'paid_at' => $p->raw_response['paid_at'] ?? null,
                'how' => match (true) {
                    isset($p->raw_response['paid']['simulated']) => 'test',
                    isset($p->raw_response['paid']['by_staff_id']) => 'staff',
                    isset($p->raw_response['paid']) => 'bakong',
                    default => null,
                },
                'paid_after_cancel' => (bool) ($p->raw_response['paid_after_cancel'] ?? false),
                'created_at' => $p->created_at,
            ])->values()),
            'allowed_next_statuses' => collect($this->status->allowedNext())
                ->filter(fn ($s) => in_array($s, OrderStatusService::STAFF_TARGETS, true))
                ->map->value->values(),
        ];
    }
}
