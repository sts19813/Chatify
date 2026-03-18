<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KiroUserContext extends Model
{
    protected $fillable = [
        'user_id',
        'user_location',
        'price_preference',
        'preference_tags',
        'interest_patterns',
        'chat_summary',
        'last_intent',
        'last_query',
        'last_result_ids',
        'last_interaction_at',
    ];

    protected function casts(): array
    {
        return [
            'preference_tags' => 'array',
            'interest_patterns' => 'array',
            'last_result_ids' => 'array',
            'last_interaction_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
