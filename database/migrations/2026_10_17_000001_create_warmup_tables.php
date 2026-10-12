<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Emails exchanged between warmup mailboxes. Each carries a random
        // token (X-Warmup header) so the recipient's inbox check finds it.
        Schema::create('warmup_messages', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->foreignId('sender_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('email_accounts')->cascadeOnDelete();
            // A reply points at the message it answers (one reply each).
            $table->foreignId('parent_id')->nullable()->unique()->constrained('warmup_messages')->nullOnDelete();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('subject', 255);
            $table->string('message_id', 255)->nullable();
            $table->string('status', 10)->default('sent'); // sent | failed
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->boolean('landed_in_spam')->default(false);
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'sent_at']);
            $table->index(['recipient_id', 'sent_at']);
        });

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->timestamp('warmup_next_at')->nullable();
            $table->timestamp('warmup_checked_at')->nullable();
            $table->string('warmup_error', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn(['warmup_next_at', 'warmup_checked_at', 'warmup_error']);
        });

        Schema::dropIfExists('warmup_messages');
    }
};
