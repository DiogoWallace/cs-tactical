<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aulas (ADR 0015, ADR 0016).
     *
     * - FK composta `(module_id, course_id)`: a aula pertence a um módulo do mesmo curso.
     * - `content` = {"version": n, "blocks": [...]}; `content_version` repete a versão e
     *   `content_hash` identifica mudanças (ADR 0017).
     * - `(id, course_id)` único: alvo da FK composta do progresso (etapa 2.4).
     */
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained('courses')->restrictOnDelete();
            $table->uuid('module_id');
            $table->string('key', 80);
            $table->string('slug', 80);
            $table->string('title', 160);
            $table->string('summary', 500)->nullable();
            $table->integer('position');
            $table->smallInteger('estimated_minutes')->nullable();
            $table->jsonb('content');
            $table->smallInteger('content_version');
            $table->char('content_hash', 64);
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['module_id', 'course_id'])->references(['id', 'course_id'])->on('course_modules')->restrictOnDelete();
            $table->unique(['course_id', 'key']);
            $table->unique(['id', 'course_id']);
            $table->index(['course_id', 'status']);
        });

        DB::statement('ALTER TABLE lessons ADD CONSTRAINT lessons_position_unique UNIQUE (module_id, position) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE lessons ADD CONSTRAINT lessons_course_id_slug_unique UNIQUE (course_id, slug) DEFERRABLE INITIALLY DEFERRED');
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_key_format_check CHECK (key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_title_not_blank_check CHECK (btrim(title) <> '')");
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement('ALTER TABLE lessons ADD CONSTRAINT lessons_position_check CHECK (position >= 0)');
        DB::statement('ALTER TABLE lessons ADD CONSTRAINT lessons_estimated_minutes_check CHECK (estimated_minutes BETWEEN 1 AND 600)');
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_content_blocks_check CHECK (jsonb_typeof(content->'blocks') = 'array')");
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_content_version_check CHECK (content_version >= 1 AND content_version = (content->>'version')::smallint)");
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_content_hash_format_check CHECK (content_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_published_at_check CHECK (status <> 'published' OR published_at IS NOT NULL)");
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_archived_at_check CHECK (status <> 'archived' OR archived_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
