<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Respostas de uma tentativa enviada (ADR 0018): opções escolhidas (validadas contra o
     * snapshot da tentativa) e se a pergunta foi acertada. A resposta não existe sem a tentativa
     * (CASCADE); a pergunta não é apagada enquanto tiver respostas (RESTRICT).
     */
    public function up(): void
    {
        Schema::create('quiz_attempt_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignUuid('question_id')->constrained('quiz_questions')->restrictOnDelete();
            $table->boolean('is_correct');
            $table->timestampsTz();

            $table->unique(['attempt_id', 'question_id']);
        });

        DB::statement('ALTER TABLE quiz_attempt_answers ADD COLUMN selected_option_ids uuid[] NOT NULL');
        DB::statement('ALTER TABLE quiz_attempt_answers ADD CONSTRAINT quiz_attempt_answers_selected_check CHECK (cardinality(selected_option_ids) >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_answers');
    }
};
