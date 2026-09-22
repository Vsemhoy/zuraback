<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class MonitoringController extends Controller
{
    public function show(): JsonResponse
    {
        $token = config('monitoring.token');
        $tokenFile = config('monitoring.token_file');
        if (! $token && is_readable($tokenFile)) {
            $token = trim(file_get_contents($tokenFile));
        }
        if (! $token) {
            return response()->json(['message' => 'Служба мониторинга ещё не настроена.'], 503);
        }
        try {
            $response = Http::connectTimeout(1)->timeout(3)->withoutRedirecting()
                ->withToken($token)->acceptJson()->get(config('monitoring.url').'/v1/storage');
        } catch (ConnectionException) {
            return response()->json(['message' => 'Служба мониторинга недоступна.'], 503);
        }
        $payload = $response->json();
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'host' => 'required|string',
            'checked_at' => 'required|date',
            'volumes' => 'required|array|min:1',
            'volumes.*.name' => 'required|string',
            'volumes.*.total_bytes' => 'required|numeric|min:1',
            'volumes.*.used_bytes' => 'required|numeric|min:0',
            'volumes.*.available_bytes' => 'required|numeric|min:0',
            'volumes.*.reserved_bytes' => 'required|numeric|min:0',
            'volumes.*.used_percent' => 'required|numeric|between:0,100',
            'volumes.*.inodes_available_percent' => 'present|nullable|numeric|between:0,100',
            'volumes.*.status' => 'required|in:ok,warning,critical',
        ]);
        if (! $response->successful() || $validator->fails()) {
            return response()->json(['message' => 'Служба мониторинга вернула некорректный ответ.'], 503);
        }
        $data = $validator->validated();
        $fields = ['name', 'total_bytes', 'used_bytes', 'available_bytes', 'reserved_bytes', 'used_percent', 'inodes_available_percent', 'status'];
        $data['volumes'] = array_map(fn (array $volume): array => array_intersect_key($volume, array_flip($fields)), $data['volumes']);

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
