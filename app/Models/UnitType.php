<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitType extends Model
{
    protected $fillable = ['name', 'monthly_fee'];
    protected function casts(): array { return ['monthly_fee' => 'decimal:2']; }
    public function units() { return $this->hasMany(Unit::class); }
}
