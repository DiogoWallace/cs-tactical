<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\ChangePassword;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * PUT /api/v1/me/password — 204.
 *
 * Depois da troca, a sessão atual é refeita com `login()`: novo ID de sessão e, se o usuário
 * usava "lembrar-me", um cookie novo (o antigo deixou de valer com o remember_token novo).
 * O hash da nova senha é gravado nesta sessão, para o AuthenticateSession do Sanctum mantê-la
 * e derrubar as demais.
 */
class ChangePasswordController
{
    public function __invoke(ChangePasswordRequest $request, ChangePassword $changePassword): Response
    {
        /** @var User $user */
        $user = $request->user();
        $guard = Auth::guard('web');
        $remember = $request->hasCookie($guard->getRecallerName());

        $changePassword->handle($user, $request->string('password')->toString());

        $guard->login($user, $remember);
        $request->session()->put('password_hash_web', $user->getAuthPassword());

        return response()->noContent();
    }
}
