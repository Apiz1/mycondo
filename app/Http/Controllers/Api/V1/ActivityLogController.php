<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ActivityLog;
use App\Http\Resources\ActivityLogResource;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'category' => ['nullable', 'alpha', 'max:30'],
            'search' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $logs = ActivityLog::with('user:id,name,role')
            ->when($request->query('category'), fn ($q, $c) => $q->where('action', 'like', $c.'.%'))
            ->when($request->query('search'), fn ($q, $s) => $q->where('description', 'ilike', '%'.$s.'%'))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id')
            ->paginate(25);

        return ActivityLogResource::collection($logs);
    }
}
