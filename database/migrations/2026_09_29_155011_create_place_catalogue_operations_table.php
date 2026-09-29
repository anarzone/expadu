<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_catalogue_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('state', ['applied', 'recovered']);
            $table->jsonb('context');
            $table->longText('receipt');
            $table->longText('recovery')->nullable();
            $table->string('actor', 191);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });
        DB::statement("ALTER TABLE place_catalogue_operations ADD CONSTRAINT place_catalogue_operations_recovery_state CHECK ((state = 'applied' AND recovery IS NULL) OR (state = 'recovered' AND recovery IS NOT NULL))");
    }

    public function down(): void
    {
        DB::transaction(function () {
            if (! Schema::hasTable('place_catalogue_operations')) {
                return;
            }
            DB::statement('LOCK TABLE place_catalogue_operations IN ACCESS EXCLUSIVE MODE');
            if (DB::table('place_catalogue_operations')->exists()) {
                throw new RuntimeException('Preserve populated catalogue recovery evidence; this migration cannot be reversed.');
            }
            Schema::drop('place_catalogue_operations');
        });
    }
};
