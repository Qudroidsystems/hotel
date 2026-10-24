<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Opening     = 'opening';      // first quantity when an item is set up
    case Receipt     = 'receipt';      // goods received from a supplier
    case TransferIn  = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Sale        = 'sale';         // used by a POS sale (recipe)
    case SaleVoid    = 'sale_void';    // POS sale voided — stock put back
    case Issue       = 'issue';        // used inside the hotel (housekeeping, staff meals…)
    case Waste       = 'waste';        // spoiled, broken, expired
    case Adjustment  = 'adjustment';   // correction
    case Count       = 'count';        // stocktake difference

    public function label(): string
    {
        return match ($this) {
            self::Opening     => 'Opening stock',
            self::Receipt     => 'Received',
            self::TransferIn  => 'Transfer in',
            self::TransferOut => 'Transfer out',
            self::Sale        => 'Sold',
            self::SaleVoid    => 'Sale voided',
            self::Issue       => 'Issued / used',
            self::Waste       => 'Waste / breakage',
            self::Adjustment  => 'Adjustment',
            self::Count       => 'Stocktake',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Opening     => 'ri-flag-line',
            self::Receipt     => 'ri-truck-line',
            self::TransferIn  => 'ri-login-box-line',
            self::TransferOut => 'ri-logout-box-r-line',
            self::Sale        => 'ri-goblet-line',
            self::SaleVoid    => 'ri-arrow-go-back-line',
            self::Issue       => 'ri-hand-heart-line',
            self::Waste       => 'ri-delete-bin-6-line',
            self::Adjustment  => 'ri-scales-3-line',
            self::Count       => 'ri-list-check-2',
        };
    }

    public function pill(): string
    {
        return match ($this) {
            self::Receipt, self::TransferIn, self::SaleVoid, self::Opening => 'st-success',
            self::Sale, self::TransferOut, self::Issue => 'st-info',
            self::Waste => 'st-danger',
            default => 'st-warning',
        };
    }

    /** Reasons staff can pick when taking stock out or correcting it by hand. */
    public static function manual(): array
    {
        return [self::Issue, self::Waste, self::Adjustment];
    }
}
