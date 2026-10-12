<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REST API keys: one workspace each, stored as a SHA-256 hash.
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 12); // shown in the UI to tell keys apart
            $table->jsonb('abilities'); // read | write
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // Outbound connections: webhooks, Slack, Telegram, HubSpot,
        // Pipedrive, Google Sheets. Credentials live in `config` (encrypted).
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name', 100);
            $table->text('config'); // encrypted JSON
            $table->jsonb('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });

        Schema::create('integration_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->boolean('success');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_deliveries');
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('api_tokens');
    }
};
