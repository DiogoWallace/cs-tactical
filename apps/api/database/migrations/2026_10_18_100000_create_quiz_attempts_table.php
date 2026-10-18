<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tentativas de quiz (ADR 0018, docs/database/academy-learning.md).
     *
     * - `snapshot`: perguntas, opções, ordem, gabarito, explicações e nota mínima congelados no
     *   início. Correção e revisão usam só o snapshot; editar o quiz não muda a tentativa.
     * - Uma tentativa aberta por jogador e quiz (único parcial); `attempt_number` único por jogador
     *   e quiz.
     * - Dado do jogador: CASCADE para `users`; RESTRICT para o quiz (conteúdo não é apagado).
     */
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('quiz_id')->constrained('quizzes')->restrictOnDelete();
            $table->smallInteger('attempt_number');
            $table->integer('quiz_revision');
            $table->string('status', 16);
            $table->jsonb('snapshot');
            $table->timestampTz('started_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->smallInteger('question_count')->nullable();
            $table->smallInteger('correct_count')->nullable();
            $table->decimal('score_percent', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'quiz_id', 'attempt_number']);
            $table->index('quiz_id');
        });

        DB::statement("CREATE UNIQUE INDEX quiz_attempts_one_open_unique ON quiz_attempts (user_id, quiz_id) WHERE status = 'in_progress'");
        DB::statement("ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_status_check CHECK (status IN ('in_progress', 'submitted'))");
        DB::statement('ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_attempt_number_check CHECK (attempt_number >= 1)');
        DB::statement('ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_quiz_revision_check CHECK (quiz_revision >= 1)');
        DB::statement("ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_snapshot_check CHECK (jsonb_typeof(snapshot->'questions') = 'array')");
        // Enviada ⇔ corrigida: data, contagens, nota e resultado juntos.
        DB::statement("ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_submitted_check CHECK (
            (status = 'submitted') = (submitted_at IS NOT NULL)
            AND (status = 'submitted') = (question_count IS NOT NULL AND correct_count IS NOT NULL AND score_percent IS NOT NULL AND passed IS NOT NULL)
        )");
        DB::statement('ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_score_check CHECK (
            score_percent IS NULL OR (score_percent BETWEEN 0 AND 100 AND question_count >= 1 AND correct_count BETWEEN 0 AND question_count)
        )');
        DB::statement('ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_dates_check CHECK (submitted_at IS NULL OR submitted_at >= started_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
