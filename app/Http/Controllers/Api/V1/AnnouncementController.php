<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\AnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;

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

        return new AnnouncementResource($announcement->load('author'));
    }

    public function update(AnnouncementRequest $request, Announcement $announcement)
    {
        $announcement->update($request->validated());

        return new AnnouncementResource($announcement->load('author'));
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return response()->noContent();
    }
}
