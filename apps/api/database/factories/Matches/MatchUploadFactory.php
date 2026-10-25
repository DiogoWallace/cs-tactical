<?php

namespace Database\Factories\Matches;

use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Models\MatchUpload;
use Database\Factories\Identity\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Envio aberto de 20 MiB em partes de 8 MiB, sem partes recebidas. Sem `for(...)`, cria também o
 * usuário. Não grava arquivo no disco.
 *
 * @extends Factory<MatchUpload>
 */
class MatchUploadFactory extends Factory
{
    protected $model = MatchUpload::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $size = 20 * 1024 * 1024;
        $part = 8 * 1024 * 1024;

        return [
            'user_id' => UserFactory::new(),
            'status' => MatchUploadStatus::Uploading,
            'name' => 'partida.dem',
            'size_bytes' => $size,
            'part_size' => $part,
            'parts_total' => (int) ceil($size / $part),
            'parts_received' => 0,
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(120),
        ];
    }

    /** Concluído, com o hash `$sha256`, expirando em 24 h. */
    public function completed(?string $sha256 = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchUploadStatus::Completed,
            'parts_received' => $attributes['parts_total'],
            'sha256' => $sha256 ?? hash('sha256', fake()->uuid()),
            'completed_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
    }
}
