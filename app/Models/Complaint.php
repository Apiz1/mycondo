<?php

namespace App\Models;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;

use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    // Complaint.php  (imports: ComplaintCategory, ComplaintStatus)
    protected $fillable = ['unit_id', 'user_id', 'category', 'title', 'description', 'status', 'photo_path', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'category' => ComplaintCategory::class,
            'status' => ComplaintStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function unit() { return $this->belongsTo(Unit::class); }
    public function reporter() { return $this->belongsTo(User::class, 'user_id'); }
    public function comments() { return $this->hasMany(ComplaintComment::class)->oldest(); }
}
