<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conta Steam vinculada ao usuário (módulo Identity, etapa M2, ADR 0022 item 2).
     *
     * - Uma conta Steam por usuário e um usuário por conta Steam: os dois únicos decidem também
     *   quando duas pessoas concluem o vínculo do mesmo SteamID ao mesmo tempo.
     * - Só o SteamID64 é guardado. Desvincular apaga a linha; excluir a conta também (CASCADE).
     */
    public function up(): void
    {
        Schema::create('user_steam_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('steam_id', 17)->unique();
            $table->timestampTz('linked_at');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE user_steam_accounts ADD CONSTRAINT user_steam_accounts_steam_id_format_check CHECK (steam_id ~ '^7656119[0-9]{10}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_steam_accounts');
    }
};
