<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['unit_type_id', 'block', 'unit_number'];
    public function unitType() { return $this->belongsTo(UnitType::class); }
    public function residencies() { return $this->hasMany(Residency::class); }
}
