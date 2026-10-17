<?php

namespace App\Modules\Academy\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Quem pode ver rascunhos e arquivados (ADR 0015, item 4; regra R3).
 *
 * As rotas do catálogo são públicas (sem `auth:sanctum`), mas uma requisição do frontend com
 * sessão ainda identifica o usuário pelo guard do Sanctum. A capacidade vem do Gate (ADR 0012):
 * a Academy não conhece papéis nem o módulo Identity.
 */
final class EditorialAccess
{
    public const ABILITY = 'manage-academy-content';

    private const ATTRIBUTE = 'academy.editorial';

    /** O usuário da requisição tem a capacidade editorial (sem sessão: não). */
    public static function allowed(Request $request): bool
    {
        return Gate::forUser($request->user('sanctum'))->allows(self::ABILITY);
    }

    /**
     * Modo editorial: pediu `include_unpublished=true` (ou `1`) **e** tem a capacidade. Sem a
     * capacidade o parâmetro é ignorado (docs/api/academy-v1.md). Calculado uma vez por
     * requisição: controllers e resources leem o mesmo valor.
     */
    public static function requested(Request $request): bool
    {
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $asked = in_array($request->query('include_unpublished'), ['true', '1'], true);
            $request->attributes->set(self::ATTRIBUTE, $asked && self::allowed($request));
        }

        return $request->attributes->get(self::ATTRIBUTE) === true;
    }
}
