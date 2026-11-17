<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\DB;

class ActivityLog extends Model
{
    use Prunable;

    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'description', 'subject_type', 'subject_id', 'meta', 'ip_address'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subDays(90));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        string $action,
        string $description,
        ?Model $subject = null,
        array $meta = [],
        ?User $actor = null,
    ): void {
        try {
            DB::transaction(fn () => static::create([
                'user_id' => ($actor ?? auth()->user())?->id,
                'action' => $action,
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'meta' => $meta ?: null,
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}