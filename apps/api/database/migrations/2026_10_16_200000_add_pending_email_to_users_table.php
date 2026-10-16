<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Troca de e-mail com confirmação (etapa 1.5, ADR 0011): o novo endereço fica em
     * `pending_email` até o usuário clicar no link enviado a ele. Não é único: dois usuários
     * podem pedir o mesmo endereço, e só o primeiro a confirmar fica com ele (o índice único
     * de `email` garante).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email', 254)->nullable()->after('email_verified_at');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_pending_email_lower_check CHECK (pending_email = lower(pending_email))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_pending_email_lower_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pending_email');
        });
    }
};
