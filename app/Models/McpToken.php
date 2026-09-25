<?php

namespace App\Models;

use App\Mcp\Auth\McpScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dedicated MCP credential (Phase 11) — never a user password. Holds only
 * the public lookup id and the SHA-256 of the secret; see the
 * `create_mcp_tokens_table` migration and docs/integrations/mcp.md.
 *
 * @property int $id
 * @property string $token_id
 * @property string $secret_hash
 * @property int $user_id
 * @property string $name
 * @property McpScope $scope
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 */
final class McpToken extends Model
{
    protected $fillable = ['token_id', 'secret_hash', 'user_id', 'name', 'scope', 'last_used_at', 'revoked_at'];

    protected $hidden = ['secret_hash'];

    protected $casts = [
        'scope' => McpScope::class,
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
