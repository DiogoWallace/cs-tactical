<?php

namespace App\Modules\Matches\Console;

use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Jobs\ExpireMatchUpload;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\UploadStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Rede de segurança da limpeza de envios (etapa M3, decisão U2), no padrão do
 * `academy:media-prune`:
 *
 * 1. expira os envios abertos ou concluídos com o prazo vencido (como o ExpireMatchUpload);
 * 2. remove arquivos do disco `matches-uploads` com mais de 24 horas que não pertencem a um envio
 *    aberto (`.part`) ou concluído (`.dem`), reconferindo cada um logo antes de remover. Cobre
 *    jobs perdidos e contas excluídas (CASCADE apaga a linha, não o arquivo).
 *
 * `--dry-run` só lista. Arquivos fora do padrão ficam e são informados.
 */
class PruneMatchUploadsCommand extends Command
{
    private const FILE = '#^uploads/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.(part|dem)$#';

    protected $signature = 'matches:prune-uploads
        {--dry-run : Lista o que seria expirado ou removido, sem mudar nada}';

    protected $description = 'Expira envios de demos vencidos e remove arquivos órfãos com mais de 24 horas';

    public function handle(UploadStorage $storage): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = 0;
        $overdue = MatchUpload::query()
            ->whereIn('status', [MatchUploadStatus::Uploading, MatchUploadStatus::Completed])
            ->where('expires_at', '<=', Carbon::now())
            ->pluck('id');
        foreach ($overdue as $id) {
            if (! $dryRun) {
                (new ExpireMatchUpload((string) $id))->handle($storage);
            }
            $expired++;
        }

        $disk = $storage->disk();
        $cutoff = Carbon::now()->subHours((int) config('matches.orphan_retention_hours'))->getTimestamp();
        $removed = $recent = $kept = 0;
        $unexpected = [];
        foreach ($disk->allFiles(UploadStorage::DIRECTORY) as $path) {
            if (preg_match(self::FILE, $path, $match) !== 1) {
                $unexpected[] = $path;

                continue;
            }
            if ($disk->lastModified($path) > $cutoff) {
                $recent++;

                continue;
            }
            if ($this->belongsToLiveUpload($match[1], $match[2])) {
                $kept++;

                continue;
            }

            if ($dryRun) {
                $this->line("  removeria {$path}");
            } else {
                $disk->delete($path);
                Log::info('matches.prune-uploads.deleted', ['path' => $path]);
            }
            $removed++;
        }

        $this->line('  envios '.($dryRun ? 'que expirariam' : 'expirados').": {$expired}");
        $this->line('  arquivos órfãos '.($dryRun ? 'que seriam removidos' : 'removidos').": {$removed}");
        $this->line("  mantidos: de envio vivo {$kept} · com menos de 24 h {$recent}");
        foreach ($unexpected as $path) {
            $this->warn("  arquivo fora do padrão (mantido, verifique): {$path}");
        }
        if ($dryRun) {
            $this->info('Simulação: nada foi alterado.');
        }

        return self::SUCCESS;
    }

    /** Reconferido logo antes de remover: `.part` de envio aberto ou `.dem` de envio concluído. */
    private function belongsToLiveUpload(string $id, string $extension): bool
    {
        $status = $extension === 'part' ? MatchUploadStatus::Uploading : MatchUploadStatus::Completed;

        return MatchUpload::query()->whereKey($id)->where('status', $status)->exists();
    }
}
