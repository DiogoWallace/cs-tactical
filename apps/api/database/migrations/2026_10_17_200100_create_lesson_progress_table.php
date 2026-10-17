<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Progresso do jogador por aula (ADR 0017, docs/database/academy-learning.md).
     *
     * - Pertence à aula, não à posição: reordenar ou mover a aula de módulo preserva o registro.
     * - FK composta `(lesson_id, course_id)` → `lessons (id, course_id)`: o `course_id`
     *   desnormalizado é sempre o curso da aula.
     * - Conclusão histórica: `completed_content_hash` guarda o hash da aula na conclusão; editar a
     *   aula depois não desfaz nada (a interface só indica "atualizada desde que você concluiu").
     * - `in_progress` fica reservado para o início de tentativa de quiz (etapa 2.5).
     */
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('lesson_id');
            $table->foreignUuid('course_id')->constrained('courses')->restrictOnDelete();
            $table->string('status', 16);
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->char('completed_content_hash', 64)->nullable();
            $table->timestampsTz();

            $table->foreign(['lesson_id', 'course_id'])->references(['id', 'course_id'])->on('lessons')->restrictOnDelete();
            $table->unique(['user_id', 'lesson_id']);
            $table->index(['user_id', 'course_id']);
        });

        DB::statement("ALTER TABLE lesson_progress ADD CONSTRAINT lesson_progress_status_check CHECK (status IN ('in_progress', 'completed'))");
        DB::statement("ALTER TABLE lesson_progress ADD CONSTRAINT lesson_progress_completed_at_check CHECK ((status = 'completed') = (completed_at IS NOT NULL))");
        DB::statement("ALTER TABLE lesson_progress ADD CONSTRAINT lesson_progress_completed_hash_check CHECK ((status = 'completed') = (completed_content_hash IS NOT NULL) AND (completed_content_hash IS NULL OR completed_content_hash ~ '^[0-9a-f]{64}$'))");
        DB::statement('ALTER TABLE lesson_progress ADD CONSTRAINT lesson_progress_dates_check CHECK (completed_at IS NULL OR completed_at >= started_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
