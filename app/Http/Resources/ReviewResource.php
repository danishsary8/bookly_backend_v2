<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            // Privacy: "Sok D." instead of the full name.
            'reviewer_name' => $this->whenLoaded('customer', fn () => self::shortName($this->customer->name)),
            'verified_purchase' => true,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? 'Customer';
        $last = count($parts) > 1 ? Str::upper(Str::substr(end($parts), 0, 1)).'.' : '';

        return trim("{$first} {$last}");
    }
}
