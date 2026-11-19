<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ComplaintStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComplaintCommentResource;
use App\Models\ActivityLog;
use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ComplaintCommentController extends Controller
{
    public function store(Request $request, Complaint $complaint)
    {
        Gate::authorize('view', $complaint);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        if ($complaint->status === ComplaintStatus::Closed) {
            return response()->json(['message' => 'This complaint is closed and no longer accepts comments.'], 409);
        }

        $comment = $complaint->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        ActivityLog::record('complaint.commented', "Comment added to complaint #{$complaint->id}", $complaint);

        return new ComplaintCommentResource($comment->load('author:id,name,role'));
    }
}