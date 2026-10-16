<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perfil público do jogador, 1:1 com `users` (módulo Identity).
     *
     * - Apelido único sem diferenciar maiúsculas (índice em lower(nickname)), mas exibido como
     *   o jogador escreveu. Formato: 3 a 24 caracteres entre letras ASCII, dígitos, "_", "-" e ".".
     * - País em ISO 3166-1 alfa-2 maiúsculo; fuso horário IANA (validado na aplicação).
     * - Avatar e links externos entram quando forem implementados (etapa 1.5 ou depois).
     */
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('nickname', 24);
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('bio', 500)->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX profiles_nickname_lower_unique ON profiles (lower(nickname))');
        DB::statement("ALTER TABLE profiles ADD CONSTRAINT profiles_nickname_format_check CHECK (nickname ~ '^[A-Za-z0-9_.-]{3,24}$')");
        DB::statement("ALTER TABLE profiles ADD CONSTRAINT profiles_country_code_check CHECK (country_code ~ '^[A-Z]{2}$')");
        DB::statement("ALTER TABLE profiles ADD CONSTRAINT profiles_timezone_not_blank_check CHECK (btrim(timezone) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
