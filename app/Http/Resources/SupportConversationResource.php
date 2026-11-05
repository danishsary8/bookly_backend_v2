<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->is('api/v1/staff/*');

        return [
            'id' => $this->id, 'topic' => $this->topic, 'order_id' => $this->order_id, 'status' => $this->status,
            'assigned_staff_id' => $this->assigned_staff_id, 'last_message_at' => $this->last_message_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(), 'unread_count' => (int) ($this->unread_count ?? 0),
            'customer' => $this->when($staff, fn () => ['id' => $this->customer_id, 'name' => $this->customer?->name ?? $this->guest_name, 'email' => $this->customer?->email ?? $this->guest_email]),
            'rating' => $this->whenLoaded('rating', fn () => $this->rating ? ['stars' => $this->rating->stars, 'comment' => $this->rating->comment] : null),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->filter(fn ($message) => $staff || ! $message->is_internal)->map(fn ($message) => [
                'id' => $message->id, 'sender' => $message->sender, 'body' => $message->body, 'is_internal' => $message->is_internal,
                'created_at' => $message->created_at->toIso8601String(), 'read_at' => $message->read_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
