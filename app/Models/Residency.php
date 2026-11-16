<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Residency extends Model
{
    protected $fillable = ['user_id', 'unit_id', 'relationship', 'moved_in_at', 'moved_out_at'];
    protected function casts(): array { return ['moved_in_at' => 'date', 'moved_out_at' => 'date']; }
    public function user() { return $this->belongsTo(User::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
