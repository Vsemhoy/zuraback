<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateContractorAvatarRequest;
use App\Models\FilerFile;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ContractorAvatarController extends Controller
{
    public function index(Scope $scope): JsonResponse
    {
        return response()->json(['data' => config('avatars.presets', [])]);
    }

    public function update(UpdateContractorAvatarRequest $request, Scope $scope, User $contractor): JsonResponse
    {
        $avatar = $request->validated('avatar');
        if ($avatar && ! empty($avatar['file_id'])) {
            $file = FilerFile::query()->where('scope_id', $scope->id)->findOrFail($avatar['file_id']);
            abort_unless($file->visibility === 'scope'
                && in_array($file->mime, ['image/jpeg', 'image/png', 'image/webp'], true)
                && $file->attachments()->count() === 1
                && $file->attachments()->where('subject_type', 'user')->where('subject_id', $contractor->id)->exists(), 422, 'Выберите фотографию этого пользователя.');
            $avatar = ['file_id' => $file->id, 'scope_id' => $scope->id, 'crop' => $avatar['crop'] ?? ['x' => 50, 'y' => 50, 'zoom' => 1]];
        }
        DB::transaction(function () use ($contractor, $avatar): void {
            $locked = User::query()->lockForUpdate()->findOrFail($contractor->id);
            $profile = $locked->profile ?? [];
            $profile['avatar'] = $avatar;
            $locked->update(['profile' => $profile]);
        });

        return response()->json(['data' => ['avatar' => $avatar]]);
    }
}
