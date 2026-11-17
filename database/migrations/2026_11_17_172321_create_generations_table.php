<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('provider_id')->nullable()->unique();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images');
            $table->string('status')->default('queued');
            $table->jsonb('pricing_snapshot');
            $table->decimal('estimated_cost', 30, 18);
            $table->decimal('estimated_charge', 30, 18);
            $table->decimal('provider_cost', 30, 18)->nullable();
            $table->decimal('user_charge', 30, 18)->nullable();
            $table->jsonb('usage')->nullable();
            $table->jsonb('rates')->nullable();
            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('sync_attempts')->default(0);
            $table->timestampTz('next_sync_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at', 'id']);
        });

        DB::statement("ALTER TABLE generations ADD CONSTRAINT generation_status CHECK (status IN ('queued', 'processing', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generation_costs CHECK (
            estimated_cost >= 0 AND estimated_charge = estimated_cost * 1.25 AND
            ((status = 'succeeded' AND provider_cost IS NOT NULL AND provider_cost >= 0 AND user_charge IS NOT NULL AND user_charge = provider_cost * 1.25)
            OR (status = 'failed' AND provider_cost IS NOT NULL AND provider_cost = 0 AND user_charge IS NOT NULL AND user_charge = 0)
            OR (status IN ('queued', 'processing') AND provider_cost IS NULL AND user_charge IS NULL))
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
