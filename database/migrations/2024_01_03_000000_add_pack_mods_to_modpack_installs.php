<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modpack_installs', function (Blueprint $table) {
            // File names the pack placed in /mods, so a later non-clean update can remove
            // exactly those (old versions) while keeping mods the user added themselves.
            if (!Schema::hasColumn('modpack_installs', 'pack_mods')) {
                $table->json('pack_mods')->nullable()->after('update_notified_version');
            }
        });
    }

    public function down(): void
    {
        // Intentionally a no-op, matching the other migrations.
    }
};
