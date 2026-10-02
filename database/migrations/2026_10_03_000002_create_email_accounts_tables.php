<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sending_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('dkim_selector')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('checks')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sending_domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('from_name');
            $table->string('provider', 20)->default('custom');

            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port');
            $table->string('smtp_encryption', 10);
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password'); // encrypted

            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port');
            $table->string('imap_encryption', 10);
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable(); // encrypted; null = same as SMTP

            // Sending limits (used by the sending engine in Phase 5).
            $table->unsignedSmallInteger('daily_limit');
            $table->unsignedInteger('min_delay_seconds');
            $table->unsignedInteger('max_delay_seconds');
            $table->string('send_window_start', 5);
            $table->string('send_window_end', 5);
            $table->json('send_days');
            $table->text('signature')->nullable();

            $table->string('tracking_domain')->nullable();
            $table->timestamp('tracking_domain_verified_at')->nullable();

            $table->string('status', 20)->default('active');
            $table->text('last_error')->nullable();
            $table->timestamp('last_tested_at')->nullable();

            // Warmup module (Phase 14). Present now so enabling it needs no migration.
            $table->boolean('warmup_enabled')->default(false);
            $table->unsignedSmallInteger('warmup_daily_target')->default(20);
            $table->unsignedTinyInteger('warmup_reply_rate')->default(30);
            $table->timestamp('warmup_started_at')->nullable();

            $table->timestamps();

            $table->unique(['workspace_id', 'email']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
        Schema::dropIfExists('sending_domains');
    }
};
