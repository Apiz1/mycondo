<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Enums\VisitorStatus;
use App\Http\Resources\GateVisitorResource;
use App\Models\ActivityLog;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GateController extends Controller
{
    public function expected(Request $request)
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $date = $request->query('date', today()->toDateString());

        $visitors = Visitor::with('unit')
            ->whereDate('visit_date', $date)
            ->whereIn('status', [VisitorStatus::Pending->value, VisitorStatus::CheckedIn->value])
            ->orderBy('name')
            ->get();

        return GateVisitorResource::collection($visitors);
    }

    public function checkIn(Request $request)
    {
        $code = $this->codeFrom($request);

        return DB::transaction(function () use ($request, $code) {
            $visitor = $this->findLocked($code);

            if (! $visitor) {
                return $this->unknownCode($code);
            }

            if ($visitor->status === VisitorStatus::Cancelled) {
                return response()->json(['message' => 'This pass was cancelled.'], 409);
            }

            if ($visitor->status !== VisitorStatus::Pending) {
                return response()->json(['message' => 'This pass has already been used.'], 409);
            }

            if (! $visitor->visit_date->isToday()) {
                return response()->json([
                    'message' => 'This pass is for '.$visitor->visit_date->toDateString().', not today.',
                ], 409);
            }

            $visitor->update([
                'status' => VisitorStatus::CheckedIn,
                'checked_in_at' => now(),
                'checked_in_by' => $request->user()->id,
            ]);

            ActivityLog::record('visitor.checked_in', "{$visitor->name} checked in at the gate", $visitor);

            return new GateVisitorResource($visitor);
        });
    }

    public function checkOut(Request $request)
    {
        $code = $this->codeFrom($request);

        return DB::transaction(function () use ($code) {
            $visitor = $this->findLocked($code);

            if (! $visitor) {
                return $this->unknownCode($code);
            }

            if ($visitor->status !== VisitorStatus::CheckedIn) {
                return response()->json(['message' => 'This visitor is not checked in.'], 409);
            }

            $visitor->update([
                'status' => VisitorStatus::CheckedOut,
                'checked_out_at' => now(),
            ]);

            ActivityLog::record('visitor.checked_out', "{$visitor->name} checked out", $visitor);

            return new GateVisitorResource($visitor);
        });
    }

    private function codeFrom(Request $request): string
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        return Str::upper(trim($data['code']));
    }

    private function findLocked(string $code): ?Visitor
    {
        return Visitor::with('unit')->where('code', $code)->lockForUpdate()->first();
    }

    private function unknownCode(string $code)
    {
        ActivityLog::record(
            'security.gate_unknown_code',
            'Unknown visitor code scanned at the gate',
            null,
            ['code' => $code],
        );

        return response()->json(['message' => 'No visitor pass matches this code.'], 404);
    }
}
