<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    protected $fillable = [
        'user_id',
        'description',
        'amount',
        'remind_at',
        'completed',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
