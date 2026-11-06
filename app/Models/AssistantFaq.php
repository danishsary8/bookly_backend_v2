<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantFaq extends Model
{
    protected $fillable = ['question_en', 'question_km', 'answer_en', 'answer_km', 'keywords', 'is_active'];

    protected function casts(): array
    {
        return ['keywords' => 'array', 'is_active' => 'boolean', 'hits' => 'integer'];
    }
}
