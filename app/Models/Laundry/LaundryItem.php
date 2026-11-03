<?php

namespace App\Models\Laundry;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One line of the laundry price list: a shirt, a suit … with a price for each service it can have. */
class LaundryItem extends Model
{
    use BelongsToHotel;

    /** service key => [label, price column] */
    public const SERVICES = [
        'wash'  => ['Wash & iron', 'wash_price'],
        'dry'   => ['Dry clean', 'dry_price'],
        'press' => ['Press only', 'press_price'],
    ];

    protected $fillable = ['name', 'group', 'wash_price', 'dry_price', 'press_price', 'sort_order', 'is_active'];

    protected $casts = [
        'wash_price'  => 'decimal:2',
        'dry_price'   => 'decimal:2',
        'press_price' => 'decimal:2',
        'is_active'   => 'boolean',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    /** The price of $service, or null when this item can't have it. */
    public function priceFor(string $service): ?float
    {
        $col = self::SERVICES[$service][1] ?? null;
        $v = $col ? $this->{$col} : null;

        return $v === null ? null : (float) $v;
    }

    /** ['wash' => 1500.0, 'press' => 800.0] — only the services offered. */
    public function services(): array
    {
        $out = [];
        foreach (array_keys(self::SERVICES) as $s) {
            if (($p = $this->priceFor($s)) !== null) {
                $out[$s] = $p;
            }
        }

        return $out;
    }

    public static function serviceLabel(string $service): string
    {
        return self::SERVICES[$service][0] ?? ucfirst($service);
    }
}
