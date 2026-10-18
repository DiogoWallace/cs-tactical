<?php

namespace App\Modules\Learning\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Http\Resources\EnrollmentSummaryResource;
use App\Modules\Learning\Queries\ProgressQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/me/enrollments: matrículas do jogador da sessão, atividade mais recente primeiro,
 * com o progresso resumido e o histórico de cursos arquivados (R13a). Sem paginação: uma por
 * curso, limitada pelo tamanho do catálogo.
 */
class MyEnrollmentsController
{
    public function __invoke(Request $request, ProgressQuery $query): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $summaries = $query->enrollments($user);
        foreach ($summaries as $summary) {
            Gate::authorize('view', $summary->enrollment);
        }

        return EnrollmentSummaryResource::collection(collect($summaries));
    }
}
