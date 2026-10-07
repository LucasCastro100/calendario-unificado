<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache local dos eventos remotos, para manter a tela utilizável quando o
     * provider falha ou excede cota. `payload` guarda o DTO já serializado.
     */
    public function up(): void
    {
        Schema::create('calendar_event_caches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('remote_id');
            $table->string('uid');
            $table->json('payload');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['user_id', 'uid'], 'calendar_event_caches_user_uid_unique');
            $table->index(['user_id', 'starts_at'], 'calendar_event_caches_user_starts_index');
            $table->index(['user_id', 'ends_at'], 'calendar_event_caches_user_ends_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_caches');
    }
};
