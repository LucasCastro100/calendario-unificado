<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma conexão por usuário por provedor, guardando as credenciais OAuth.
     *
     * `access_token` e `refresh_token` são `text` porque o valor criptografado
     * (base64 com IV) fica maior que o token original e pode passar de 255 chars.
     * A criptografia acontece no cast `encrypted` do model, não na migration.
     */
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_account_id')->nullable();
            $table->string('account_email')->nullable();
            $table->json('scopes')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('calendar_ids')->nullable();
            $table->timestamp('scopes_revoked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider'], 'calendar_connections_user_provider_unique');
            $table->index('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_connections');
    }
};
