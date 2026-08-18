<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE assets
            ADD COLUMN active_asset_tag VARCHAR(191)
            GENERATED ALWAYS AS (
                CASE
                    WHEN deleted_at IS NULL THEN asset_tag
                    ELSE NULL
                END
            ) VIRTUAL
        ");

        Schema::table('assets', function (Blueprint $table) {
            $table->unique('active_asset_tag', 'assets_active_asset_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique('assets_active_asset_tag_unique');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('active_asset_tag');
        });
    }
};
