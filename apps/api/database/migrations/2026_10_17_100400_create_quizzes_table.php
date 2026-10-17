<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quizzes, perguntas e opções (ADR 0018).
     *
     * - Um quiz **ativo** por aula (único parcial). Um quiz substituído por outro (nova `key`,
     *   decisão P2) é aposentado (`retired_at`) e continua no histórico das tentativas.
     * - Perguntas e opções de quiz com tentativas são aposentadas, não apagadas; a posição é única
     *   só entre as ativas.
     */
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('lessons')->restrictOnDelete();
            $table->string('key', 80)->unique();
            $table->string('title', 160);
            $table->smallInteger('max_attempts')->default(3);
            $table->smallInteger('pass_percent')->default(70);
            $table->integer('revision')->default(1);
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX quizzes_active_lesson_unique ON quizzes (lesson_id) WHERE retired_at IS NULL');
        DB::statement("ALTER TABLE quizzes ADD CONSTRAINT quizzes_key_format_check CHECK (key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE quizzes ADD CONSTRAINT quizzes_title_not_blank_check CHECK (btrim(title) <> '')");
        DB::statement('ALTER TABLE quizzes ADD CONSTRAINT quizzes_max_attempts_check CHECK (max_attempts BETWEEN 1 AND 20)');
        DB::statement('ALTER TABLE quizzes ADD CONSTRAINT quizzes_pass_percent_check CHECK (pass_percent BETWEEN 1 AND 100)');
        DB::statement('ALTER TABLE quizzes ADD CONSTRAINT quizzes_revision_check CHECK (revision >= 1)');

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quiz_id')->constrained('quizzes')->restrictOnDelete();
            $table->string('key', 80);
            $table->string('kind', 16);
            $table->text('prompt');
            $table->text('explanation')->nullable();
            $table->integer('position');
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();

            $table->unique(['quiz_id', 'key']);
        });

        DB::statement('CREATE UNIQUE INDEX quiz_questions_active_position_unique ON quiz_questions (quiz_id, position) WHERE retired_at IS NULL');
        DB::statement("ALTER TABLE quiz_questions ADD CONSTRAINT quiz_questions_key_format_check CHECK (key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE quiz_questions ADD CONSTRAINT quiz_questions_kind_check CHECK (kind IN ('single_choice', 'multiple_choice'))");
        DB::statement("ALTER TABLE quiz_questions ADD CONSTRAINT quiz_questions_prompt_check CHECK (btrim(prompt) <> '' AND char_length(prompt) <= 1000)");
        DB::statement('ALTER TABLE quiz_questions ADD CONSTRAINT quiz_questions_explanation_check CHECK (char_length(explanation) <= 2000)');
        DB::statement('ALTER TABLE quiz_questions ADD CONSTRAINT quiz_questions_position_check CHECK (position >= 0)');

        Schema::create('quiz_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('question_id')->constrained('quiz_questions')->restrictOnDelete();
            $table->string('key', 80);
            $table->string('text', 500);
            $table->boolean('is_correct');
            $table->integer('position');
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();

            $table->unique(['question_id', 'key']);
        });

        DB::statement('CREATE UNIQUE INDEX quiz_options_active_position_unique ON quiz_options (question_id, position) WHERE retired_at IS NULL');
        DB::statement("ALTER TABLE quiz_options ADD CONSTRAINT quiz_options_key_format_check CHECK (key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE quiz_options ADD CONSTRAINT quiz_options_text_not_blank_check CHECK (btrim(text) <> '')");
        DB::statement('ALTER TABLE quiz_options ADD CONSTRAINT quiz_options_position_check CHECK (position >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_options');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
    }
};
