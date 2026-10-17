<?php

namespace App\Modules\Academy\Http\Controllers;

use App\Modules\Academy\Http\EditorialAccess;
use App\Modules\Academy\Http\MediaResponse;
use App\Modules\Academy\Queries\CatalogQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/academy/media/{sha256}.{ext} — imagem do disco `academy` (ADR 0019).
 *
 * O nome do arquivo (validado na rota) só serve para achar o registro em `academy_media`; o
 * caminho lido do disco é o do registro, nunca um valor da URL. Servida a qualquer um só se for
 * capa de curso público ou imagem de aula pública; quem tem `manage-academy-content` também
 * recebe as de rascunho e arquivados. Nos demais casos, 404.
 */
class MediaController
{
    public function __construct(private readonly CatalogQuery $catalog) {}

    public function __invoke(Request $request, string $file): Response
    {
        [$sha256, $extension] = explode('.', $file, 2);

        $media = $this->catalog->media($sha256, $extension) ?? abort(404);

        // A cada requisição: a resposta não é guardada em cache (MediaResponse).
        if (! $this->catalog->mediaIsPublic($media) && ! EditorialAccess::allowed($request)) {
            abort(404);
        }

        return MediaResponse::make($media);
    }
}
