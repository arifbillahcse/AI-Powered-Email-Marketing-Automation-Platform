<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Private-to-the-workspace notes about a lead ("called, asked for next week").
        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->string('color', 20)->default('gray')->after('name');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('starred')->default(false);
            // A calendar date in the workspace's time zone, not an instant.
            $table->date('follow_up_on')->nullable();
            $table->foreignId('follow_up_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('follow_up_notified_at')->nullable();

            $table->index(['workspace_id', 'follow_up_on']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'follow_up_on']);
            $table->dropConstrainedForeignId('follow_up_by');
            $table->dropColumn(['starred', 'follow_up_on', 'follow_up_notified_at']);
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn('color');
        });

        Schema::dropIfExists('lead_notes');
    }
};
