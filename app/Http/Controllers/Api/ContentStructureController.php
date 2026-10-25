<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SaveContentStructureRequest;
use App\Models\Scope;
use App\Services\ContentStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContentStructureController extends Controller
{
    public function __construct(private readonly ContentStructureService $structure) {}

    public function index(Request $request, Scope $scope, string $module): JsonResponse
    {
        return response()->json(['data' => $this->structure->catalog($request, $scope, $module)]);
    }

    public function store(SaveContentStructureRequest $request, Scope $scope, string $module, string $kind): JsonResponse
    {
        return response()->json(['data' => $this->structure->save($request, $scope, $module, $kind, $request->validated())], 201);
    }

    public function update(SaveContentStructureRequest $request, Scope $scope, string $module, string $kind, string $item): JsonResponse
    {
        return response()->json(['data' => $this->structure->save($request, $scope, $module, $kind, $request->validated(), $item)]);
    }

    public function destroy(Request $request, Scope $scope, string $module, string $kind, string $item): Response
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->structure->delete($request, $scope, $module, $kind, $item, $data['revision']);

        return response()->noContent();
    }
}
