<?php

namespace App\Modules\Identity\Http\Responses;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/** Login: 200 com o usuário, no mesmo formato de GET /api/v1/me. */
class LoginResponse implements LoginResponseContract
{
    /** @param  Request  $request */
    public function toResponse($request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new UserResource($user->loadMissing('profile')))->response($request);
    }
}
