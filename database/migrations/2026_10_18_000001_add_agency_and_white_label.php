<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // A client workspace belongs to an agency workspace: it uses the
            // agency's plan and branding. One level only.
            $table->foreignId('parent_id')->nullable()->constrained('workspaces')->nullOnDelete();

            // White label.
            $table->string('brand_name', 100)->nullable();
            $table->string('brand_logo_path')->nullable();
            $table->string('brand_color', 7)->nullable(); // #rrggbb
            $table->string('custom_domain', 253)->nullable()->unique();
            $table->timestamp('custom_domain_verified_at')->nullable();

            // Client reports: a secret share link and an optional email schedule.
            $table->text('report_token')->nullable(); // encrypted
            $table->string('report_token_hash', 64)->nullable()->unique();
            $table->jsonb('report_recipients')->nullable();
            $table->string('report_frequency', 10)->default('off'); // off | weekly | monthly
            $table->timestamp('report_last_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropUnique(['custom_domain']);
            $table->dropUnique(['report_token_hash']);
            $table->dropColumn([
                'brand_name', 'brand_logo_path', 'brand_color', 'custom_domain', 'custom_domain_verified_at',
                'report_token', 'report_token_hash', 'report_recipients', 'report_frequency', 'report_last_sent_at',
            ]);
        });
    }
};
