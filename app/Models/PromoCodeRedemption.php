<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $promo_code_id
 * @property int $order_id
 * @property int|null $user_id
 * @property string $currency
 * @property int $discount_minor
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PromoCodeRedemption extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
