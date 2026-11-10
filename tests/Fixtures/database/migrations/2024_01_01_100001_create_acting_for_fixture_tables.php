<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Holders that are not users — the shapes "acting for" exists for: an AI Agent,
 * a connected app, an app's API key, and an accounting firm's grant on a client.
 * Each can be revoked, which is what CanBeInactive reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['agents', 'connected_apps', 'api_keys', 'access_grants'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['access_grants', 'api_keys', 'connected_apps', 'agents'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
