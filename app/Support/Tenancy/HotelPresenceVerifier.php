<?php

namespace App\Support\Tenancy;

use Illuminate\Validation\DatabasePresenceVerifier;

/**
 * "exists:rooms,id" and "unique:rooms,number" only look at the current hotel's rows, so a form can't pick
 * another hotel's room and room numbers only need to be unique within a hotel.
 */
class HotelPresenceVerifier extends DatabasePresenceVerifier
{
    protected function table($table)
    {
        $query = parent::table($table);
        $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
        if (in_array($name, HotelTables::LIST, true) && ($id = CurrentHotel::get()->id())) {
            $query->where('hotel_id', $id);
        }

        return $query;
    }
}
