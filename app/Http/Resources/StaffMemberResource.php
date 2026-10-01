<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class StaffMemberResource extends StaffUserResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at,
        ];
    }
}
