<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tokens de redefinição de senha, no formato esperado pelo broker do Laravel
     * (config/auth.php). Tabela técnica do framework: sem UUID (ADR 0005).
     * O broker guarda só o hash do token; a expiração (60 min) é verificada pelo `created_at`.
     */
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 254)->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
