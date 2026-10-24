<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of running cost (diesel, salaries, repairs…), with an optional monthly budget. */
class ExpenseCategory extends Model
{
    /** Groups used to total the profit & loss. */
    public const GROUPS = [
        'staff'       => 'Staff costs',
        'utilities'   => 'Power & utilities',
        'operations'  => 'Operating supplies & services',
        'maintenance' => 'Repairs & maintenance',
        'sales'       => 'Sales & marketing',
        'admin'       => 'Administration',
        'finance'     => 'Bank charges & finance',
        'other'       => 'Other',
    ];

    protected $fillable = ['name', 'group', 'monthly_budget', 'is_active', 'sort_order'];

    protected $casts = ['monthly_budget' => 'decimal:2', 'is_active' => 'boolean'];

    public function expenses(): HasMany { return $this->hasMany(Expense::class, 'category_id'); }

    public function scopeOrdered($q) { return $q->orderBy('sort_order')->orderBy('name'); }

    public function getGroupLabelAttribute(): string { return self::GROUPS[$this->group] ?? ucfirst($this->group); }
}
