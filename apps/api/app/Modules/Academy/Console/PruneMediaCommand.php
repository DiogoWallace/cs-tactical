<?php

namespace App\Modules\Academy\Console;

use App\Modules\Academy\Import\MediaStore;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Media;
use App\Modules\Academy\Support\ContentLock;
use App\Modules\Academy\Support\ContentLockedException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Remove imagens órfãs do disco `academy` (ADR 0019, item 14): arquivos sem linha em
 * academy_media, deixados por uma importação que falhou depois de gravá-los.
 *
 * - Mesmo lock da importação: nunca roda junto com ela.
 * - Só arquivos com mais de 24 horas.
 * - Reconfere cada arquivo logo antes de remover: sem linha em academy_media (que cobre capas,
 *   por FK) e sem aula citando o hash. Aula citando hash sem registro é anomalia: o arquivo
 *   fica e o caso é informado.
 * - Remove um por um, com log. `--dry-run` só lista.
 */
class PruneMediaCommand extends Command
{
    private const MEDIA_PATH = '#^media/([0-9a-f]{2})/([0-9a-f]{64})\.(png|jpg|webp|avif)$#';

    protected $signature = 'academy:media-prune
        {--dry-run : Lista o que seria removido, sem remover}';

    protected $description = 'Remove imagens da Academy sem registro no banco, com mais de 24 horas';

    public function handle(ContentLock $lock, MediaStore $store): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            return $lock->run(fn () => $this->prune($store->disk(), $dryRun));
        } catch (ContentLockedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function prune(Filesystem $disk, bool $dryRun): int
    {
        $cutoff = Carbon::now()->subHours((int) config('academy.media_retention_hours'))->getTimestamp();
        $removed = $recent = $referenced = 0;
        $anomalies = $unexpected = [];

        foreach ($disk->allFiles('media') as $path) {
            if (preg_match(self::MEDIA_PATH, $path, $match) !== 1 || ! str_starts_with($match[2], $match[1])) {
                $unexpected[] = $path;

                continue;
            }
            if ($disk->lastModified($path) > $cutoff) {
                $recent++;

                continue;
            }

            $sha = $match[2];
            if (Media::query()->where('sha256', $sha)->orWhere('path', $path)->exists()) {
                $referenced++;

                continue;
            }
            if ($this->lessonCites($sha)) {
                $anomalies[] = $path;
                Log::warning('academy.media-prune.anomaly', ['path' => $path, 'reason' => 'aula cita o hash sem registro em academy_media']);

                continue;
            }

            $this->remove($disk, $path, $dryRun);
            $removed++;
        }

        // Temporários de uma gravação interrompida: nunca são referenciados.
        foreach ($disk->allFiles('tmp') as $path) {
            if ($disk->lastModified($path) <= $cutoff) {
                $this->remove($disk, $path, $dryRun);
                $removed++;
            }
        }

        $verb = $dryRun ? 'seriam removidos' : 'removidos';
        $this->line("  órfãos {$verb}: {$removed}");
        $this->line("  mantidos: em uso {$referenced} · com menos de 24 h {$recent}");
        foreach ($anomalies as $path) {
            $this->warn("  anomalia (aula cita o arquivo sem registro; mantido): {$path}");
        }
        foreach ($unexpected as $path) {
            $this->warn("  arquivo fora do padrão (mantido, verifique): {$path}");
        }
        if ($dryRun) {
            $this->info('Simulação: nada foi removido.');
        }

        return $anomalies === [] ? self::SUCCESS : self::FAILURE;
    }

    private function lessonCites(string $sha): bool
    {
        return Lesson::query()
            ->whereRaw("jsonb_path_exists(content, '\$.blocks[*] ? (@.data.media == \$sha)', jsonb_build_object('sha', ?::text))", [$sha])
            ->exists();
    }

    private function remove(Filesystem $disk, string $path, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line("  removeria {$path}");

            return;
        }
        $disk->delete($path);
        Log::info('academy.media-prune.deleted', ['path' => $path]);
    }
}
