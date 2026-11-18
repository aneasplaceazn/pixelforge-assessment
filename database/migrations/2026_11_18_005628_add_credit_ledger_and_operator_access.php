<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_operator')->default(false);
        });
        Schema::create('credit_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::table('generations', function (Blueprint $table): void {
            $table->foreignUuid('credit_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider_scope', 64)->nullable();
            $table->jsonb('provider_metadata')->nullable();
            $table->uuid('deleted_owner_id')->nullable();
            $table->unique(['id', 'credit_account_id']);
            $table->index(['completed_at', 'user_id']);
        });
        Schema::create('credit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('credit_account_id')->constrained()->restrictOnDelete();
            $table->uuid('generation_id')->nullable();
            $table->uuid('request_key')->nullable();
            $table->string('kind');
            $table->decimal('amount', 30, 18);
            $table->decimal('available_delta', 30, 18);
            $table->decimal('reserved_delta', 30, 18);
            $table->decimal('outstanding_delta', 30, 18);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['generation_id', 'credit_account_id'])->references(['id', 'credit_account_id'])->on('generations')->restrictOnDelete();
            $table->unique(['generation_id', 'kind']);
            $table->unique(['credit_account_id', 'request_key', 'kind']);
            $table->index(['credit_account_id', 'id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE credit_entries ADD CONSTRAINT credit_entry_operation CHECK (
                amount <> 'NaN'::numeric AND available_delta <> 'NaN'::numeric
                AND reserved_delta <> 'NaN'::numeric AND outstanding_delta <> 'NaN'::numeric AND amount >= 0 AND (
                    (kind = 'purchase' AND generation_id IS NULL AND request_key IS NOT NULL AND amount IN (5,10,20)
                        AND available_delta = amount AND reserved_delta = 0 AND outstanding_delta = 0)
                    OR (kind = 'debt_payment' AND ((generation_id IS NULL AND request_key IS NOT NULL) OR (generation_id IS NOT NULL AND request_key IS NULL)) AND amount > 0
                        AND available_delta = -amount AND reserved_delta = 0 AND outstanding_delta = -amount)
                    OR (kind = 'reserve' AND generation_id IS NOT NULL AND request_key IS NULL
                        AND available_delta = -amount AND reserved_delta = amount AND outstanding_delta = 0)
                    OR (kind = 'settle' AND generation_id IS NOT NULL AND request_key IS NULL
                        AND available_delta <= 0 AND reserved_delta <= 0 AND outstanding_delta >= 0
                        AND amount = -available_delta - reserved_delta + outstanding_delta)
                    OR (kind = 'release' AND generation_id IS NOT NULL AND request_key IS NULL
                        AND available_delta = amount AND reserved_delta = -amount AND outstanding_delta = 0)
                )
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_credit_ledger() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE available numeric; reserved numeric; outstanding numeric; generation_reserved numeric;
                generation_status text; expected numeric;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Credit history is append-only' USING ERRCODE = '23514';
                END IF;
                PERFORM id FROM credit_accounts WHERE id = NEW.credit_account_id FOR UPDATE;
                SELECT COALESCE(SUM(available_delta),0), COALESCE(SUM(reserved_delta),0), COALESCE(SUM(outstanding_delta),0)
                    INTO available, reserved, outstanding FROM credit_entries WHERE credit_account_id = NEW.credit_account_id;
                IF available + NEW.available_delta < 0 OR reserved + NEW.reserved_delta < 0 OR outstanding + NEW.outstanding_delta < 0 THEN
                    RAISE EXCEPTION 'Insufficient credit or invalid release' USING ERRCODE = '23514';
                END IF;
                IF NEW.kind = 'reserve' AND outstanding > 0 THEN
                    RAISE EXCEPTION 'Outstanding credits must be paid first' USING ERRCODE = '23514';
                END IF;
                IF NEW.kind = 'debt_payment' AND NOT EXISTS (
                    SELECT 1 FROM credit_entries WHERE credit_account_id = NEW.credit_account_id AND amount >= NEW.amount
                        AND ((request_key = NEW.request_key AND kind = 'purchase') OR (generation_id = NEW.generation_id AND kind = 'release'))
                ) THEN
                    RAISE EXCEPTION 'Debt payments require a purchase or released credits' USING ERRCODE = '23514';
                END IF;
                IF NEW.generation_id IS NOT NULL THEN
                    SELECT status, CASE WHEN NEW.kind = 'reserve' THEN estimated_charge ELSE user_charge END
                        INTO generation_status, expected FROM generations WHERE id = NEW.generation_id;
                    SELECT COALESCE(SUM(reserved_delta),0) INTO generation_reserved FROM credit_entries WHERE generation_id = NEW.generation_id;
                    IF generation_reserved + NEW.reserved_delta < 0 THEN
                        RAISE EXCEPTION 'Reservation cannot be released twice' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.kind IN ('settle','release') AND NOT EXISTS (
                        SELECT 1 FROM credit_entries WHERE generation_id = NEW.generation_id AND kind = 'reserve'
                    ) THEN
                        RAISE EXCEPTION 'Settlement requires a reservation' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.kind = 'release' AND generation_status = 'succeeded' AND NOT EXISTS (
                        SELECT 1 FROM credit_entries WHERE generation_id = NEW.generation_id AND kind = 'settle'
                    ) THEN
                        RAISE EXCEPTION 'Successful charges must settle before release' USING ERRCODE = '23514';
                    END IF;
                    IF (NEW.kind = 'reserve' AND (generation_status NOT IN ('queued','processing') OR NEW.amount <> expected))
                        OR (NEW.kind = 'settle' AND (generation_status <> 'succeeded' OR NEW.amount <> expected))
                        OR (NEW.kind = 'release' AND (generation_status NOT IN ('succeeded','failed') OR NEW.amount <> generation_reserved)) THEN
                        RAISE EXCEPTION 'Credit operation does not match generation' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER credit_ledger_guard BEFORE INSERT OR UPDATE OR DELETE ON credit_entries
                FOR EACH ROW EXECUTE FUNCTION protect_credit_ledger();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('credit_entries')->exists()) {
            throw new RuntimeException('Credit history exists. Use a forward repair; do not discard financial records.');
        }
        Schema::dropIfExists('credit_entries');
        DB::statement('DROP FUNCTION IF EXISTS protect_credit_ledger()');
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropForeign(['credit_account_id']);
            $table->dropUnique(['id', 'credit_account_id']);
            $table->dropIndex(['completed_at', 'user_id']);
            $table->dropColumn(['credit_account_id', 'provider_scope', 'provider_metadata', 'deleted_owner_id']);
        });
        Schema::dropIfExists('credit_accounts');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_operator'));
    }
};
