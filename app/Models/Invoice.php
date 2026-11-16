<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Enums\InvoiceStatus;

class Invoice extends Model
{
   protected $fillable = ['unit_id', 'period', 'amount', 'due_date', 'status'];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
        ];
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
