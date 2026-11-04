<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Two levels at most (owner 2026-11-05): a top-level category ("Fiction") and its children ("Fantasy"). */
class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'parent_id', 'sort_order', 'icon'];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'sort_order' => 'integer'];
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_categories');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * The category ids a filter on these categories covers: each one plus its children.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function withDescendants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $children = self::whereIn('parent_id', $ids)->pluck('id')->all();

        return array_values(array_unique([...$ids, ...array_map('intval', $children)]));
    }
}
