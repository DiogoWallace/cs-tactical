<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Envios de demos do módulo Matches (etapa M3, ADR 0022 itens 3 e 5,
     * docs/training/mvp-m3-envio-de-demos.md).
     *
     * - Um envio aberto por usuário: único parcial em `user_id` com status `uploading`, que também
     *   decide dois inícios simultâneos.
     * - O mesmo arquivo (SHA-256) uma vez por usuário entre os concluídos (U5).
     * - Dado do jogador: excluir a conta apaga os envios (CASCADE); os arquivos que sobrarem são
     *   órfãos que o `matches:prune-uploads` remove.
     * - Nada da partida é guardado aqui além do tamanho e do hash do arquivo.
     */
    public function up(): void
    {
        Schema::create('match_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('name', 255);
            $table->bigInteger('size_bytes');
            $table->integer('part_size');
            $table->integer('parts_total');
            $table->integer('parts_received')->default(0);
            $table->char('last_part_sha256', 64)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('rejection_code', 40)->nullable();
            $table->timestampTz('last_activity_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX match_uploads_one_open_per_user ON match_uploads (user_id) WHERE status = \'uploading\'');
        DB::statement('CREATE UNIQUE INDEX match_uploads_user_sha256_completed ON match_uploads (user_id, sha256) WHERE status = \'completed\'');
        DB::statement('CREATE INDEX match_uploads_user_id_created_at_index ON match_uploads (user_id, created_at DESC)');

        DB::statement("ALTER TABLE match_uploads ADD CONSTRAINT match_uploads_status_check CHECK (status IN ('uploading', 'completed', 'rejected', 'cancelled', 'expired'))");
        DB::statement('ALTER TABLE match_uploads ADD CONSTRAINT match_uploads_size_check CHECK (size_bytes BETWEEN 1 AND 629145600)');
        DB::statement('ALTER TABLE match_uploads ADD CONSTRAINT match_uploads_parts_check CHECK (part_size > 0 AND parts_total > 0 AND parts_received BETWEEN 0 AND parts_total)');
        DB::statement("ALTER TABLE match_uploads ADD CONSTRAINT match_uploads_sha256_check CHECK (sha256 IS NULL OR sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE match_uploads ADD CONSTRAINT match_uploads_last_part_sha256_check CHECK (last_part_sha256 IS NULL OR last_part_sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE match_uploads ADD CONSTRAINT match_uploads_rejection_check CHECK ((status = 'rejected') = (rejection_code IS NOT NULL))");
        DB::statement("ALTER TABLE match_uploads ADD CONSTRAINT match_uploads_completed_check CHECK (status <> 'completed' OR (completed_at IS NOT NULL AND sha256 IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('match_uploads');
    }
};
