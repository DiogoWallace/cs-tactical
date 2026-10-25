<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CompleteSteamLink;
use App\Modules\Identity\Actions\StartSteamLink;
use App\Modules\Identity\Actions\UnlinkSteamAccount;
use App\Modules\Identity\Http\Requests\CompleteSteamLinkRequest;
use App\Modules\Identity\Http\Requests\UnlinkSteamRequest;
use App\Modules\Identity\Http\Resources\SteamAccountResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Steam\SteamLinkFailed;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Vínculo da conta Steam do usuário da sessão (etapa M2, docs/training/mvp-m2-vinculo-steam.md).
 * As rotas não recebem id de conta: tudo é do próprio usuário (UserPolicy::manageSteamLink).
 */
class SteamLinkController
{
    /** POST /api/v1/me/steam/link — 200 `{ redirect_url }`. */
    public function start(Request $request, StartSteamLink $startSteamLink): JsonResponse
    {
        $user = $this->authorizedUser($request);

        return response()->json(['redirect_url' => $startSteamLink->handle($user, $this->session($request))]);
    }

    /** POST /api/v1/me/steam/link/complete — 200 `{ steam: { steam_id, linked_at } }`. */
    public function complete(CompleteSteamLinkRequest $request, CompleteSteamLink $completeSteamLink): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $account = $completeSteamLink->handle(
            $user,
            $this->session($request),
            $request->validated('state'),
            $request->openIdResponse(),
        );

        return response()->json(['steam' => (new SteamAccountResource($account))->resolve($request)]);
    }

    /** DELETE /api/v1/me/steam — 204. */
    public function destroy(UnlinkSteamRequest $request, UnlinkSteamAccount $unlinkSteamAccount): Response
    {
        /** @var User $user */
        $user = $request->user();

        $unlinkSteamAccount->handle($user);

        return response()->noContent();
    }

    private function authorizedUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('manageSteamLink', $user);

        return $user;
    }

    /** O `state` vive na sessão do navegador; sem sessão não há como iniciar nem concluir. */
    private function session(Request $request): Session
    {
        if (! $request->hasSession()) {
            throw SteamLinkFailed::stateInvalid();
        }

        return $request->session();
    }
}
