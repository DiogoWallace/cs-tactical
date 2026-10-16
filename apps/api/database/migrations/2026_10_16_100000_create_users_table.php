<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contas de acesso (módulo Identity). Dados públicos do jogador ficam em `profiles`.
     *
     * - UUIDv7 como chave primária (ADR 0005).
     * - E-mail único sem diferenciar maiúsculas: índice único em lower(email). O model também
     *   grava o e-mail normalizado, então buscas por igualdade usam o índice comum.
     * - Papel na plataforma como string + check constraint (ADR 0007, docs/database).
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->string('platform_role', 16)->default('user');
            $table->rememberToken();
            $table->timestampsTz();

            $table->index('email');
        });

        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_platform_role_check CHECK (platform_role IN ('user', 'editor', 'admin'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_email_not_blank_check CHECK (btrim(email) <> '')");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_name_not_blank_check CHECK (btrim(name) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
