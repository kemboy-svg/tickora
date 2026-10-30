<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment provider's credentials. Secrets are encrypted at rest and
 * hidden from serialisation; the admin screen only ever sees whether a
 * secret is set and its last four characters.
 *
 * @property int $id
 * @property PaymentProvider $provider
 * @property bool $enabled
 * @property bool $is_default
 * @property string|null $public_key
 * @property string|null $secret_key
 * @property string|null $webhook_secret
 * @property int|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PaymentGatewaySetting extends Model
{
    protected $table = 'payment_gateways';

    /**
     * @var list<string>
     */
    protected $hidden = ['secret_key', 'webhook_secret'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => false,
        'is_default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    public static function for(PaymentProvider $provider): ?self
    {
        return self::query()->where('provider', $provider->value)->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasSecretKey(): bool
    {
        return is_string($this->secret_key) && $this->secret_key !== '';
    }

    /**
     * Ready to take payments: switched on and has its secret key.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->hasSecretKey();
    }

    /**
     * "test" or "live", read from the key prefix both providers use.
     */
    public function mode(): ?string
    {
        if (! $this->hasSecretKey()) {
            return null;
        }

        return str_contains((string) $this->secret_key, '_live_') ? 'live' : 'test';
    }

    public static function mask(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return '••••'.substr($secret, -4);
    }
}
