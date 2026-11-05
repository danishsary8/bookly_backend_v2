<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportConversation extends Model
{
    public const TOPICS = ['order', 'payment', 'ebook', 'delivery', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'closed_at' => 'datetime', 'last_customer_seen_at' => 'datetime', 'last_reply_email_at' => 'datetime', 'anonymised_at' => 'datetime', 'version' => 'integer'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }

    public function rating(): HasOne
    {
        return $this->hasOne(SupportRating::class, 'conversation_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
