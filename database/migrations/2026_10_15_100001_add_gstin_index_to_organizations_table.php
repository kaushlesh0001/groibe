<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client import finds an organization by GSTIN for every row, and client registration checks that a GSTIN is not
     * taken (review finding, Pass 9 item 8). Without an index each of those scans the table. Not unique: the spec lists no
     * such constraint, and soft-deleted organizations keep their value.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->index('gstin');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['gstin']);
        });
    }
};
