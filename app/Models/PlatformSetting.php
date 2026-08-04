<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single platform-wide setting written by a platform admin from the UI.
 *
 * Prefer `App\Support\Platform\PlatformSettings` over talking to this model
 * directly: the service caches and merges live values with .env defaults,
 * so callers do not need to know whether the row exists.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property int|null $updated_by_user_id
 */
#[Fillable(['key', 'value', 'updated_by_user_id'])]
class PlatformSetting extends Model
{
    protected function casts(): array
    {
        return [
            // encrypted so a stolen DB backup does not leak API keys.
            'value' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
