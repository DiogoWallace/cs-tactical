<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matrículas do módulo Learning (ADR 0015, ADR 0017, docs/database/academy-learning.md).
     *
     * - Uma por jogador e curso: o único `(user_id, course_id)` é o que torna a matrícula
     *   idempotente mesmo com requisições simultâneas (INSERT ... ON CONFLICT).
     * - Dado do jogador: excluir a conta apaga a matrícula (CASCADE). O curso não pode ser apagado
     *   com matrículas (RESTRICT): conteúdo publicado é arquivado, nunca apagado.
     * - `completed_at` é gravado ao concluir a última aula pública e não é apagado depois (R12).
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('courses')->restrictOnDelete();
            $table->timestampTz('enrolled_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('last_activity_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'course_id']);
            $table->index('course_id');
        });

        // "Continuar de onde parou" e "Meu progresso": matrículas do jogador, mais recentes primeiro.
        DB::statement('CREATE INDEX enrollments_user_id_last_activity_at_index ON enrollments (user_id, last_activity_at DESC)');
        DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_completed_at_check CHECK (completed_at IS NULL OR completed_at >= enrolled_at)');
        DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_last_activity_at_check CHECK (last_activity_at >= enrolled_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
