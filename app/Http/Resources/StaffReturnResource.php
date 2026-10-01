<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class StaffReturnResource extends ReturnResource
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
            'handled_by' => $this->whenLoaded('handledBy', fn () => $this->handledBy ? ['id' => $this->handledBy->id, 'name' => $this->handledBy->name] : null),
        ];
    }
}
