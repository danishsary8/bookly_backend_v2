<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookEmbedding extends Model
{
    use HasFactory;

    protected $table = 'book_embeddings';

    protected $fillable = [
        'book_id',
        'embedding',
        'model',
        'text_hash',
        'embedded_at',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'embedded_at' => 'datetime',
        ];
    }

    /**
     * Set the embedding attribute. Formats arrays for pgvector or jsonb storage.
     *
     * @param  list<float>|string  $value
     */
    public function setEmbeddingAttribute(array|string $value): void
    {
        if (is_array($value)) {
            $this->attributes['embedding'] = '['.implode(',', array_map(fn ($v) => (float) $v, $value)).']';
        } else {
            $this->attributes['embedding'] = $value;
        }
    }

    /**
     * Get the embedding as a list of floats.
     *
     * @return list<float>|null
     */
    public function getEmbeddingAttribute(string|array|null $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_map(fn ($v) => (float) $v, $value);
        }

        // Parse string formatted like "[0.1,0.2,0.3]"
        $trimmed = trim($value, '[] ');
        if ($trimmed === '') {
            return [];
        }

        return array_map('floatval', explode(',', $trimmed));
    }

    /**
     * The book this embedding belongs to.
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
