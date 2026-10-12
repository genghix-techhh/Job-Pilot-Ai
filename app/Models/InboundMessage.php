<?php

namespace App\Models;

use App\Enums\MessageClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'classification' => MessageClass::class,
            'confidence' => 'float',
            'extracted' => 'array',
            'candidate_application_ids' => 'array',
            'received_at' => 'datetime',
            'ai_classified' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
