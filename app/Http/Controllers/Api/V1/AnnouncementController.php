<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\AnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\ActivityLog;
use App\Models\Residency;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use Illuminate\Support\Facades\Notification;

class AnnouncementController extends Controller
{
    public function index()
    {
        return AnnouncementResource::collection(
            Announcement::with('author')->orderByDesc('is_pinned')->latest()->paginate(15)
        );
    }

    public function show(Announcement $announcement)
    {
        return new AnnouncementResource($announcement->load('author'));
    }

    public function store(AnnouncementRequest $request)
    {
        $announcement = Announcement::create([
            ...$request->validated(),
            'author_id' => $request->user()->id,
        ]);

        ActivityLog::record('announcement.created', "Announcement posted: {$announcement->title}", $announcement);

        User::whereIn('id', Residency::whereNull('moved_out_at')->select('user_id'))
            ->where('id', '!=', $request->user()->id)
            ->chunkById(200, fn ($users) => Notification::send($users, new AnnouncementPosted($announcement)));

        return new AnnouncementResource($announcement->load('author'));
    }

    public function update(AnnouncementRequest $request, Announcement $announcement)
    {
        $announcement->update($request->validated());

        ActivityLog::record('announcement.updated', "Announcement edited: {$announcement->title}", $announcement);

        return new AnnouncementResource($announcement->load('author'));
    }

    public function destroy(Announcement $announcement)
    {
        ActivityLog::record('announcement.deleted', "Announcement deleted: {$announcement->title}", null, ['announcement_id' => $announcement->id]);

        $announcement->delete();

        return response()->noContent();
    }
}
