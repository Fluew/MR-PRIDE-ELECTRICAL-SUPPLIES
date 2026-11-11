<?php

namespace App\Models;

use App\Traits\TracksDeletions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inquiry extends Model
{
    use HasFactory, TracksDeletions;

    protected $fillable = [
        'team_id',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'priority',
        'assigned_to_id',
        'admin_notes',
        'reply_message',
        'replied_at',
        'replied_by_id',
        'ip_address',
        'user_agent',
        'deleted_by_id',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by_id');
    }
}
