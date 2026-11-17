<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('generations')->whereIn('status', ['queued', 'processing'])
            ->whereNull('retry_not_before')->where('next_sync_at', '>', now())
            ->update(['retry_not_before' => DB::raw('next_sync_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Preserve deadlines on rollback; the column migration removes them if rolled back too.
    }
};
