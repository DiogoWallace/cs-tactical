<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me — o usuário da sessão atual.
 *
 * Sem Policy: a rota só devolve o próprio usuário autenticado, sem parâmetro de recurso.
 */
class MeController
{
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->loadMissing('profile'));
    }
}
