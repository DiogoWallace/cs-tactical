<?php

namespace App\Modules\Academy;

use App\Modules\Academy\Console\ImportContentCommand;
use App\Modules\Academy\Console\PruneMediaCommand;
use App\Modules\Academy\Contracts\QuizUsage;
use App\Modules\Academy\Support\NoQuizUsage;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo Academy: catálogo de cursos, aulas e quizzes (ADRs 0015 a 0019).
 *
 * Etapa 2.1: banco, importação de conteúdo e limpeza de mídia. Sem rotas HTTP ainda (2.2).
 */
class AcademyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QuizUsage::class, NoQuizUsage::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImportContentCommand::class, PruneMediaCommand::class]);
        }
    }
}
