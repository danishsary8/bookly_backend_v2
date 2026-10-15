<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_en',
        'subject_km',
        'body_en',
        'body_km',
        'book_ids',
        'status',
        'created_by_staff_id',
        'recipients_total',
        'sent_at',
    ];

    protected $casts = [
        'book_ids' => 'array',
        'sent_at' => 'datetime',
        'recipients_total' => 'integer',
    ];

    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_id');
    }
}
