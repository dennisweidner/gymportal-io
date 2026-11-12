<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A confirmed write that could not be executed because the gym is a mirror.
 * A human carries it out in the source portal; the next import closes it.
 */
class AgentPendingAction extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'gym_id',
        'gym_api_client_id',
        'type',
        'member_number',
        'member_name',
        'portal_member_id',
        'payload',
        'effect',
        'status',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'effect' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function close(string $status, string $by): void
    {
        $this->update(['status' => $status, 'closed_by' => $by, 'closed_at' => now()]);
    }
}
