<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One import of a studio export into a mirrored gym.
 */
class AgentMirrorSync extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'gym_id',
        'source',
        'content_hash',
        'exported_at',
        'stats',
        'portal_member_ids',
        'actions_closed',
    ];

    protected function casts(): array
    {
        return [
            'exported_at' => 'datetime',
            'created_at' => 'datetime',
            'stats' => 'array',
            'portal_member_ids' => 'array',
        ];
    }

    public static function latestFor(int $gymId): ?self
    {
        return self::where('gym_id', $gymId)->orderByDesc('id')->first();
    }
}
