<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cursos da Academy (ADR 0015, docs/database/academy-learning.md).
     *
     * - `key` é a identidade imutável do arquivo de conteúdo; `slug` pode mudar (ADR 0016).
     * - Posição e slug únicos adiáveis: a importação reordena e troca slugs numa transação.
     * - Conteúdo publicado é arquivado, nunca apagado (FKs RESTRICT nas tabelas filhas).
     */
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 80)->unique();
            $table->string('slug', 80);
            $table->string('title', 160);
            $table->string('summary', 500);
            $table->string('level', 16);
            $table->string('status', 16)->default('draft');
            $table->integer('position');
            $table->foreignUuid('cover_media_id')->nullable()->constrained('academy_media')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'level', 'position']);
        });

        DB::statement('ALTER TABLE courses ADD CONSTRAINT courses_position_unique UNIQUE (position) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE courses ADD CONSTRAINT courses_slug_unique UNIQUE (slug) DEFERRABLE INITIALLY DEFERRED');
        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_key_format_check CHECK (key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_title_not_blank_check CHECK (btrim(title) <> '')");
        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_summary_not_blank_check CHECK (btrim(summary) <> '')");
        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_level_check CHECK (level IN ('beginner', 'intermediate', 'advanced'))");
        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement('ALTER TABLE courses ADD CONSTRAINT courses_position_check CHECK (position >= 0)');
        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_published_at_check CHECK (status <> 'published' OR published_at IS NOT NULL)");
        DB::statement("ALTER TABLE courses ADD CONSTRAINT courses_archived_at_check CHECK (status <> 'archived' OR archived_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
