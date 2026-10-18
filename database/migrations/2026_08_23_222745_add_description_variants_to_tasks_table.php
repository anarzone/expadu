<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one task carry paragraphs addressed to different people.
 *
 * The civilian spine exists as six near-identical cards per concept because a
 * task could hold exactly one description. They are not really duplicates: the
 * EU card explains freedom of movement, the student card ties registration to
 * university enrolment, the family card says "including children". Merging them
 * used to mean rewriting all of that into one paragraph — authoring legal
 * content — which is why the spine was only ever approved for one branch.
 *
 * With variants the merge moves each sentence verbatim instead, so six reviews
 * become one and nothing is reworded on the way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->jsonb('description_variants')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('description_variants');
        });
    }
};
