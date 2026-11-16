<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
   protected $fillable = ['invoice_id', 'paid_by', 'verified_by', 'method', 'amount', 'status', 'proof_path', 'note', 'verified_at'];
   
   protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function payer() { return $this->belongsTo(User::class, 'paid_by'); }
}
