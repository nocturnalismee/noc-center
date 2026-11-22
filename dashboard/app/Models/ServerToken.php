<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerToken extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id',
        'token_hash',
        'token_prefix',
        'label',
        'last_used_at',
        'last_used_ip',
        'expires_at',
        'revoked_at',
        'rotated_from_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
