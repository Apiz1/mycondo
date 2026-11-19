<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplaintComment extends Model
{
    // ComplaintComment.php
    protected $fillable = ['complaint_id', 'user_id', 'body'];

    public function complaint() { return $this->belongsTo(Complaint::class); }
    public function author() { return $this->belongsTo(User::class, 'user_id'); }
}
