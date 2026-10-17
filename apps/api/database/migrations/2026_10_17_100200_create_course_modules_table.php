<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulos de um curso (ADR 0015). `(id, course_id)` é único para servir de alvo à FK
     * composta das aulas: a aula sempre pertence a um módulo do mesmo curso.
     */
    public function up(): void
    {
        Schema::create('course_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained('courses')->restrictOnDelete();
            $table->string('key', 80);
            $table->string('slug', 80);
            $table->string('title', 160);
            $table->string('summary', 500)->nullable();
            $table->integer('position');
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['course_id', 'key']);
            $table->unique(['id', 'course_id']);
        });

        DB::statement('ALTER TABLE course_modules ADD CONSTRAINT course_modules_position_unique UNIQUE (course_id, position) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE course_modules ADD CONSTRAINT course_modules_course_id_slug_unique UNIQUE (course_id, slug) DEFERRABLE INITIALLY DEFERRED');
        DB::statement("ALTER TABLE course_modules ADD CONSTRAINT course_modules_key_format_check CHECK (key ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE course_modules ADD CONSTRAINT course_modules_slug_format_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE course_modules ADD CONSTRAINT course_modules_title_not_blank_check CHECK (btrim(title) <> '')");
        DB::statement("ALTER TABLE course_modules ADD CONSTRAINT course_modules_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement('ALTER TABLE course_modules ADD CONSTRAINT course_modules_position_check CHECK (position >= 0)');
        DB::statement("ALTER TABLE course_modules ADD CONSTRAINT course_modules_published_at_check CHECK (status <> 'published' OR published_at IS NOT NULL)");
        DB::statement("ALTER TABLE course_modules ADD CONSTRAINT course_modules_archived_at_check CHECK (status <> 'archived' OR archived_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('course_modules');
    }
};
