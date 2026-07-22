<?php

namespace Soul\PalworldAdmin\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger of bans issued through the plugin. The server's banlist.txt is the
 * source of truth for WHO is banned; this table enriches those entries with
 * everything known at ban time (name, level, ip, issuer, reason).
 */
class PalworldBan extends Model
{
    protected $table = 'palworld_bans';

    protected $fillable = [
        'server_id', 'user_id', 'name', 'account_name', 'player_id',
        'ip', 'level', 'message', 'banned_by',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
