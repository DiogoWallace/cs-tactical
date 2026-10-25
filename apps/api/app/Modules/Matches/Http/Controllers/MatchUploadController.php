<?php

namespace App\Modules\Matches\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Matches\Actions\CancelMatchUpload;
use App\Modules\Matches\Actions\CompleteMatchUpload;
use App\Modules\Matches\Actions\StartMatchUpload;
use App\Modules\Matches\Actions\StoreMatchUploadPart;
use App\Modules\Matches\Http\Requests\StartMatchUploadRequest;
use App\Modules\Matches\Http\Resources\MatchUploadResource;
use App\Modules\Matches\Models\MatchUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Envio de demos em partes (etapa M3, docs/training/mvp-m3-envio-de-demos.md). Toda leitura do
 * envio é pelo id **e** pelo dono: de outro usuário ou inexistente, 404.
 */
class MatchUploadController
{
    /** POST /api/v1/matches/uploads — 201 `{ upload }`. */
    public function store(StartMatchUploadRequest $request, StartMatchUpload $start): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $upload = $start->handle($user, $request->string('name')->toString(), $request->integer('size'));

        return response()->json(['upload' => (new MatchUploadResource($upload))->resolve($request)], 201);
    }

    /** GET /api/v1/matches/uploads/{upload} — o estado, para retomar ou acompanhar. */
    public function show(Request $request, string $upload): JsonResponse
    {
        $found = $this->owned($request, $upload, 'view');

        return response()->json(['upload' => (new MatchUploadResource($found))->resolve($request)]);
    }

    /**
     * PUT /api/v1/matches/uploads/{upload}/parts/{number} — corpo binário, header
     * `X-Content-SHA256`. O corpo só é lido pela ação, depois de conferir número e tamanho.
     */
    public function part(Request $request, string $upload, string $number, StoreMatchUploadPart $store): JsonResponse
    {
        $found = $this->owned($request, $upload, 'update');
        $length = $request->header('Content-Length');

        $stored = $store->handle(
            $found,
            (int) $number,
            is_numeric($length) ? (int) $length : null,
            strtolower((string) $request->header('X-Content-SHA256')),
            fn (): string => (string) $request->getContent(),
        );

        return response()->json(['upload' => (new MatchUploadResource($stored))->resolve($request)]);
    }

    /** POST /api/v1/matches/uploads/{upload}/complete — 200 `{ upload }` concluído. */
    public function complete(Request $request, string $upload, CompleteMatchUpload $complete): JsonResponse
    {
        $found = $this->owned($request, $upload, 'update');

        return response()->json(['upload' => (new MatchUploadResource($complete->handle($found)))->resolve($request)]);
    }

    /** DELETE /api/v1/matches/uploads/{upload} — 204; apaga o arquivo. */
    public function destroy(Request $request, string $upload, CancelMatchUpload $cancel): Response
    {
        $cancel->handle($this->owned($request, $upload, 'update'));

        return response()->noContent();
    }

    private function owned(Request $request, string $id, string $ability): MatchUpload
    {
        /** @var User $user */
        $user = $request->user();
        $upload = MatchUpload::query()->whereKey($id)->where('user_id', $user->getKey())->firstOrFail();
        Gate::authorize($ability, $upload);

        return $upload;
    }
}
