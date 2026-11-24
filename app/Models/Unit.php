<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['unit_type_id', 'block', 'unit_number'];
    public function unitType() { return $this->belongsTo(UnitType::class); }
    public function residencies() { return $this->hasMany(Residency::class); }

    public function activeResidents()
    {
        return $this->belongsToMany(User::class, 'residencies')
            ->wherePivotNull('moved_out_at');
    }
    
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function getLabelAttribute(): string
    {
        return ($this->block ? $this->block.'-' : '').$this->unit_number;
    }
}
