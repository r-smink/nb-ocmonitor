<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRun extends Model
{
    protected $fillable = ['customer_id', 'due_on', 'done_at', 'done_by', 'notes', 'report_sent_at'];

    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'done_at' => 'datetime',
            'report_sent_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
