<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imagens da Academy gravadas no disco `academy` (ADR 0019).
     *
     * - O arquivo é endereçado pelo hash do conteúdo (`media/{aa}/{sha256}.{ext}`) e imutável.
     * - A linha só é criada na transação da importação, depois que o arquivo existe e teve o hash
     *   conferido; arquivo sem linha é órfão (removível por `academy:media-prune`).
     * - Sem `updated_at`: o registro não muda.
     */
    public function up(): void
    {
        Schema::create('academy_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('sha256', 64)->unique();
            $table->string('path', 255)->unique();
            $table->string('mime', 16);
            $table->integer('bytes');
            $table->smallInteger('width');
            $table->smallInteger('height');
            $table->timestampTz('created_at');
        });

        DB::statement("ALTER TABLE academy_media ADD CONSTRAINT academy_media_sha256_format_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE academy_media ADD CONSTRAINT academy_media_path_check CHECK (path = 'media/' || left(sha256, 2) || '/' || sha256 || '.' || CASE mime WHEN 'image/png' THEN 'png' WHEN 'image/jpeg' THEN 'jpg' WHEN 'image/webp' THEN 'webp' WHEN 'image/avif' THEN 'avif' END)");
        DB::statement("ALTER TABLE academy_media ADD CONSTRAINT academy_media_mime_check CHECK (mime IN ('image/png', 'image/jpeg', 'image/webp', 'image/avif'))");
        DB::statement('ALTER TABLE academy_media ADD CONSTRAINT academy_media_bytes_check CHECK (bytes BETWEEN 1 AND 2097152)');
        DB::statement('ALTER TABLE academy_media ADD CONSTRAINT academy_media_dimensions_check CHECK (width BETWEEN 1 AND 4096 AND height BETWEEN 1 AND 4096)');
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_media');
    }
};
