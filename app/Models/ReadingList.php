<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReadingList extends Model
{
    protected $fillable = ['title', 'description', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'is_staff_pick' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'reading_list_books', 'list_id', 'book_id')
            ->withPivot(['position', 'note'])->withTimestamps()->orderByPivot('position')->orderBy('books.id');
    }

    public function scopePubliclyVisible($query)
    {
        return $query->where('is_public', true)->whereHas('customer', fn ($q) => $q->where('is_active', true));
    }
}
