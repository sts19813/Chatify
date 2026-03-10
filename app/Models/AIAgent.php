<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIAgent extends Model
{
    protected $table = 'ai_agents';

    protected $fillable = [
        'user_id',
        'agent_key',
        'agent_type',
        'enabled',
        'dataset_path',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
