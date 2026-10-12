<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A spam complaint (feedback-loop report), set once like the other
        // engagement timestamps.
        Schema::table('email_messages', function (Blueprint $table) {
            $table->timestamp('complained_at')->nullable();
        });

        // Why sending was paused automatically, and when the user resumed it
        // (bounce/complaint rates only count emails sent after that).
        foreach (['email_accounts', 'campaigns'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->text('paused_reason')->nullable();
                $table->timestamp('safety_reset_at')->nullable();
            });
        }

        Schema::table('sending_domains', function (Blueprint $table) {
            $table->string('blacklist_status', 10)->default('pending'); // pending | clean | listed
            $table->jsonb('blacklist')->nullable();
            $table->timestamp('blacklist_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sending_domains', function (Blueprint $table) {
            $table->dropColumn(['blacklist_status', 'blacklist', 'blacklist_checked_at']);
        });

        foreach (['email_accounts', 'campaigns'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['paused_reason', 'safety_reset_at']);
            });
        }

        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn('complained_at');
        });
    }
};
