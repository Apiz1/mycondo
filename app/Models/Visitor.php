<?php

namespace App\Models;
use App\Enums\VisitorStatus;

use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    protected $fillable = ['unit_id', 'user_id', 'checked_in_by', 'name', 'vehicle_plate', 'visit_date', 'code', 'status', 'checked_in_at', 'checked_out_at'];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'status' => VisitorStatus::class,
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function unit() { return $this->belongsTo(Unit::class); }
    public function registrar() { return $this->belongsTo(User::class, 'user_id'); }
    public function checkedInBy() { return $this->belongsTo(User::class, 'checked_in_by'); }

    public static function generateCode(): string
    {
        // No 0/O or 1/I, so a code typed by hand is not misread
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';

            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
