<?php

use App\Composer\WorkspaceUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plans now go straight in with their own Undo. Stored workspaces move to
 * that shape once: an unfinished draft is kept in the conversation as an
 * earlier proposal to reapply (never applied for the person), and applied
 * versions become changes. The revision moves on, so an open tab reloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        $upgrade = app(WorkspaceUpgrade::class);
        DB::table('composer_workspaces')->orderBy('id')->chunkById(200, function ($rows) use ($upgrade): void {
            foreach ($rows as $row) {
                $state = json_decode($row->state, true);
                if (! is_array($state) || ($state['schema'] ?? 1) >= WorkspaceUpgrade::SCHEMA) {
                    continue;
                }
                DB::table('composer_workspaces')->where('id', $row->id)->update([
                    'state' => json_encode($upgrade->toCurrent($state), JSON_THROW_ON_ERROR),
                    'revision' => $row->revision + 1,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** The old shape can't be rebuilt from changes; the upgraded state stays readable. */
    public function down(): void {}
};
