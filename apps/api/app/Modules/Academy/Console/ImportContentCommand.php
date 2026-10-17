<?php

namespace App\Modules\Academy\Console;

use App\Modules\Academy\Content\ValidationErrors;
use App\Modules\Academy\Import\ContentImporter;
use App\Modules\Academy\Import\ContentParser;
use App\Modules\Academy\Import\ContentValidationException;
use App\Modules\Academy\Import\ImportReport;
use App\Modules\Academy\Support\ContentLock;
use App\Modules\Academy\Support\ContentLockedException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Importa o conteúdo da Academy (ADR 0016, docs/academy/conteudo-importavel.md).
 *
 * Valida tudo antes de gravar; qualquer erro cancela sem tocar no banco. Nunca apaga conteúdo:
 * itens que sumiram dos arquivos são listados e só são arquivados com `--archive-missing`
 * (com confirmação, ou `--force`).
 */
class ImportContentCommand extends Command
{
    protected $signature = 'academy:import
        {path? : Pasta de conteúdo (padrão: config academy.content_path)}
        {--dry-run : Valida e mostra o que mudaria, sem gravar nada}
        {--archive-missing : Arquiva cursos, módulos e aulas que não estão nos arquivos}
        {--force : Não pede confirmação para --archive-missing}';

    protected $description = 'Importa cursos, aulas e quizzes da Academy a partir dos arquivos de conteúdo';

    public function handle(ContentParser $parser, ContentImporter $importer, ContentLock $lock): int
    {
        $path = (string) ($this->argument('path') ?? config('academy.content_path'));
        $dryRun = (bool) $this->option('dry-run');
        $archiveMissing = (bool) $this->option('archive-missing');

        try {
            return $lock->run(function () use ($parser, $importer, $path, $dryRun, $archiveMissing) {
                $errors = new ValidationErrors;
                $plan = $parser->parse($path, $errors);
                if ($plan === null) {
                    return $this->failWith($errors);
                }

                if ($archiveMissing && ! $dryRun && ! $this->confirmArchive($importer->import($plan, dryRun: true, archiveMissing: true))) {
                    $this->warn('Importação cancelada: nada foi gravado.');

                    return self::FAILURE;
                }

                $report = $importer->import($plan, $dryRun, $archiveMissing);
                $this->line("academy:import {$path}".($dryRun ? ' --dry-run' : ''));
                foreach ($report->lines() as $line) {
                    $this->line($line);
                }
                $this->line('  erros: 0');

                if ($dryRun) {
                    $this->info('Simulação: nada foi gravado.');
                } else {
                    Log::info('academy.import.completed', ['path' => $path, 'counts' => $report->counts,
                        'media_created' => $report->mediaCreated, 'files_written' => $report->filesWritten, 'archived' => $report->archived]);
                    $this->info($report->changedAnything() ? 'Importação concluída.' : 'Importação concluída: nada mudou.');
                }

                return self::SUCCESS;
            });
        } catch (ContentLockedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ContentValidationException $exception) {
            return $this->failWith($exception->errors);
        } catch (Throwable $exception) {
            Log::error('academy.import.failed', ['path' => $path, 'exception' => $exception]);
            $this->error('Importação falhou e foi desfeita no banco: '.$exception->getMessage());
            $this->line('Imagens já gravadas ficam sem registro e são removidas por academy:media-prune depois de 24 horas.');

            return self::FAILURE;
        }
    }

    private function failWith(ValidationErrors $errors): int
    {
        foreach ($errors->all() as $error) {
            $this->line("  {$error}");
        }
        $this->error("erros: {$errors->count()} — nada foi gravado.");

        return self::FAILURE;
    }

    private function confirmArchive(ImportReport $preview): bool
    {
        if ($preview->archived === []) {
            return true;
        }
        $this->warn('Serão arquivados (deixam de aparecer para os alunos):');
        foreach ($preview->archived as $item) {
            $this->line("  {$item}");
        }

        return (bool) $this->option('force') || $this->confirm('Arquivar esses itens?', false);
    }
}
