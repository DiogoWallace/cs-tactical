<?php

namespace App\Modules\Academy\Http;

use App\Modules\Academy\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Resposta de uma imagem da Academy (ADR 0019).
 *
 * - `Content-Type` do registro (conferido pelo conteúdo na importação), nunca deduzido do
 *   arquivo. `nosniff`, CSP e `Cross-Origin-Resource-Policy` vêm do Nginx da API
 *   (infra/docker/api/nginx/security.conf), como em toda resposta dela.
 * - Arquivo ausente ou com tamanho diferente do registro: 404 e erro no log (sem caminho na
 *   resposta). O hash não é recalculado a cada requisição: o arquivo é imutável e foi
 *   conferido ao ser gravado (MediaStore).
 * - Sem cache (`no-store`), pública ou editorial, e sem `ETag`/304: a decisão de acesso muda
 *   quando o conteúdo é arquivado ou volta a rascunho, e cada requisição precisa passar de novo
 *   pela autorização do MediaController. Nada guardado (navegador, proxy ou CDN) pode ser
 *   reaproveitado com base numa decisão anterior.
 */
final class MediaResponse
{
    /** Chamado só depois da autorização (MediaController). */
    public static function make(Media $media): Response
    {
        $disk = Storage::disk((string) config('academy.media_disk'));

        if (! $disk->exists($media->path) || $disk->size($media->path) !== $media->bytes) {
            Log::error('Academy: arquivo de mídia ausente ou diferente do registro', [
                'media_id' => $media->id,
                'sha256' => $media->sha256,
            ]);
            abort(404);
        }

        $response = new StreamedResponse(function () use ($disk, $media): void {
            $stream = $disk->readStream($media->path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => $media->mime,
            'Content-Length' => (string) $media->bytes,
            'Content-Disposition' => 'inline; filename="'.basename($media->path).'"',
        ]);

        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
