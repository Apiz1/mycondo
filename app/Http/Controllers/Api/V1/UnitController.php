<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;


class UnitController extends Controller
{
    public function index()
    {
        return UnitResource::collection(
            Unit::with('unitType')->orderBy('block')->orderBy('unit_number')->paginate(15)
        );
    }

    public function store(StoreUnitRequest $request)
    {
        $unit = Unit::create($request->validated());

        return new UnitResource($unit->load('unitType'));
    }

    public function show(Unit $unit)
    {
        return new UnitResource($unit->load('unitType'));
    }

    public function update(UpdateUnitRequest $request, Unit $unit)
    {
        $unit->update($request->validated());

        return new UnitResource($unit->load('unitType'));
    }

    public function destroy(Unit $unit)
    {
        if ($unit->residencies()->exists()) {
            return response()->json(['message' => 'This unit has residents and cannot be deleted.'], 409);
        }

        $unit->delete();

        return response()->noContent();
    }
}
