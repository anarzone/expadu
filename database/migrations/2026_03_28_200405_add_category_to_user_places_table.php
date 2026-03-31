<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->string('category')->default('custom')->after('sort_order'); // home, work, custom
        });

        // Set category for existing places by name
        DB::table('user_places')->where('name', 'Home')->update(['category' => 'home']);
        DB::table('user_places')->where('name', 'Work')->update(['category' => 'work']);
    }

    public function down(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
