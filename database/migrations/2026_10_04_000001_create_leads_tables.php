<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            // Denormalised for domain-level suppression and filtering.
            $table->string('email_domain');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('title')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            // Lead's own time zone, for time-zone-aware sending (Phase 5).
            $table->string('timezone')->nullable();
            // Anything else from the CSV, usable as {{variables}}.
            $table->json('custom_fields')->nullable();
            $table->string('status', 20)->default('new');
            $table->string('source', 20)->default('manual');
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'email']);
            $table->index(['workspace_id', 'email_domain']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('lead_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('lead_list_lead', function (Blueprint $table) {
            $table->foreignId('lead_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['lead_list_id', 'lead_id']);
            $table->index('lead_id');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('lead_tag', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            $table->primary(['tag_id', 'lead_id']);
            $table->index('lead_id');
        });

        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('match', 3)->default('all'); // all | any
            $table->json('rules');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('description');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // email | domain
            $table->string('value');
            $table->string('reason', 20)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('segments');
        Schema::dropIfExists('lead_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('lead_list_lead');
        Schema::dropIfExists('lead_lists');
        Schema::dropIfExists('leads');
    }
};
