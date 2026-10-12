<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Google / Microsoft 365 mailboxes connected with OAuth (XOAUTH2):
        // no password, just encrypted tokens.
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->string('auth_type', 10)->default('password'); // password | oauth
            $table->text('oauth_access_token')->nullable(); // encrypted
            $table->text('oauth_refresh_token')->nullable(); // encrypted
            $table->timestamp('oauth_expires_at')->nullable();
            $table->text('smtp_password')->nullable()->change();
        });

        // Email verification results.
        Schema::table('leads', function (Blueprint $table) {
            $table->string('verification_status', 10)->nullable(); // valid | risky | invalid | unknown
            $table->string('verification_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->index(['workspace_id', 'verification_status']);
        });

        // A/B/n testing: the step's own subject/body is version A; more
        // versions (B, C, ...) live in campaign_step_variants.
        Schema::create('campaign_step_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_step_id')->constrained()->cascadeOnDelete();
            $table->string('label', 2);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->unique(['campaign_step_id', 'label']);
        });

        Schema::table('campaign_steps', function (Blueprint $table) {
            $table->string('ab_winner', 2)->nullable(); // once set, only this version is sent
            $table->timestamp('ab_decided_at')->nullable();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('verified_only')->default(false);
            $table->boolean('ab_auto_winner')->default(true);
            $table->string('ab_metric', 10)->default('reply'); // reply | open | click
            $table->unsignedInteger('ab_min_sends')->default(100);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->string('variant', 2)->nullable(); // null = A (sent before A/B testing)
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', fn (Blueprint $table) => $table->dropColumn('variant'));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn(['verified_only', 'ab_auto_winner', 'ab_metric', 'ab_min_sends']));
        Schema::table('campaign_steps', fn (Blueprint $table) => $table->dropColumn(['ab_winner', 'ab_decided_at']));
        Schema::dropIfExists('campaign_step_variants');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'verification_status']);
            $table->dropColumn(['verification_status', 'verification_reason', 'verified_at']);
        });
        Schema::table('email_accounts', fn (Blueprint $table) => $table->dropColumn(['auth_type', 'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at']));
    }
};
