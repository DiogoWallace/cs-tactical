<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\ConfirmEmailChange;
use App\Modules\Identity\Actions\RequestEmailChange;
use App\Modules\Identity\Http\Requests\RequestEmailChangeRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\EmailChangeLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Troca de e-mail com confirmação no novo endereço (ADR 0011). */
class EmailChangeController
{
    /** POST /api/v1/me/email — 202 com o usuário (`pending_email` preenchido). */
    public function store(RequestEmailChangeRequest $request, RequestEmailChange $requestEmailChange): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $requestEmailChange->handle($user, $request->string('email')->toString());

        return (new UserResource($user->load('profile')))->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /** DELETE /api/v1/me/email — cancela o pedido pendente. */
    public function destroy(Request $request, RequestEmailChange $requestEmailChange): UserResource
    {
        $user = $this->authorizedUser($request);

        return new UserResource($requestEmailChange->cancel($user)->load('profile'));
    }

    /**
     * GET /api/v1/me/email/confirm/{id}/{hash} — chamado pela página /confirmar-email do
     * frontend. A assinatura (relativa) é validada pela rota; aqui, que o link é desta conta e
     * do endereço pendente atual.
     */
    public function confirm(Request $request, string $id, string $hash, ConfirmEmailChange $confirmEmailChange): UserResource
    {
        $user = $this->authorizedUser($request);

        abort_unless(hash_equals((string) $user->getKey(), $id) && EmailChangeLink::matches($user, $hash), 403);

        return new UserResource($confirmEmailChange->handle($user)->load('profile'));
    }

    private function authorizedUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('updateCredentials', $user);

        return $user;
    }
}
