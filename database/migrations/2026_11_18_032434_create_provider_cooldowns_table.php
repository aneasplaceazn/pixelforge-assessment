<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_cooldowns', function (Blueprint $table) {
            $table->string('key', 128)->primary();
            $table->bigInteger('deadline')->default(0);
            $table->uuid('pending_token')->nullable();
            $table->timestampTz('pending_since')->nullable();
        });
        DB::statement('ALTER TABLE provider_cooldowns ADD CONSTRAINT provider_cooldown_deadline_valid CHECK (deadline BETWEEN 0 AND 253402300799), ADD CONSTRAINT provider_cooldown_pending_valid CHECK ((pending_token IS NULL) = (pending_since IS NULL))');
    }

    public function down(): void
    {
        if (DB::table('provider_cooldowns')->exists()) {
            throw new RuntimeException('Provider coordination records must be reviewed before rollback.');
        }
        Schema::dropIfExists('provider_cooldowns');
    }
};
