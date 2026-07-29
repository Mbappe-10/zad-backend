<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialLedgerEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function source()
    {
        return $this->morphTo();
    }
}
