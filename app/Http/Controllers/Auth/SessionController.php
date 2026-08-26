<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function store(LoginRequest $request): UserResource
    {
        $identity = $request->string('identity')->toString();
        $field = filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (! Auth::attempt([$field => $identity, 'password' => $request->string('password')->toString()], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['identity' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages(['identity' => 'This account is disabled.']);
        }

        return new UserResource($user);
    }

    public function show(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    public function destroy(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
