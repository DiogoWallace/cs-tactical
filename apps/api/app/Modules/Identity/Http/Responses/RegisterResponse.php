<?php

namespace App\Modules\Identity\Http\Responses;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

/** Cadastro: 201 com o usuário já autenticado, no mesmo formato de GET /api/v1/me. */
class RegisterResponse implements RegisterResponseContract
{
    /** @param  Request  $request */
    public function toResponse($request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new UserResource($user->loadMissing('profile')))
            ->response($request)
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
