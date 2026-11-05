<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportQuickReply extends Model
{
    protected $fillable = ['title', 'body_en', 'body_km'];
}
