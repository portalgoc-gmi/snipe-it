<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->timestamp('in_transit_at')->nullable()->after('requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropColumn('in_transit_at');
        });
    }
};
