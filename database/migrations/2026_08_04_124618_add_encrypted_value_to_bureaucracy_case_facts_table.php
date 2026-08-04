<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bureaucracy_case_facts', function (Blueprint $table) {
            $table->text('encrypted_value')->nullable();
        });

        DB::table('bureaucracy_case_facts')
            ->orderBy('id')
            ->chunkById(100, function ($facts): void {
                foreach ($facts as $fact) {
                    $rawValue = is_string($fact->value)
                        ? $fact->value
                        : json_encode($fact->value, JSON_THROW_ON_ERROR);

                    DB::table('bureaucracy_case_facts')
                        ->where('id', $fact->id)
                        ->update([
                            'value' => json_encode(['protected' => true], JSON_THROW_ON_ERROR),
                            'encrypted_value' => Crypt::encryptString($rawValue),
                        ]);
                }
            });

        Schema::table('bureaucracy_case_facts', function (Blueprint $table) {
            $table->text('encrypted_value')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('bureaucracy_case_facts')
            ->orderBy('id')
            ->chunkById(100, function ($facts): void {
                foreach ($facts as $fact) {
                    DB::table('bureaucracy_case_facts')
                        ->where('id', $fact->id)
                        ->update(['value' => Crypt::decryptString($fact->encrypted_value)]);
                }
            });

        Schema::table('bureaucracy_case_facts', function (Blueprint $table) {
            $table->dropColumn('encrypted_value');
        });
    }
};
