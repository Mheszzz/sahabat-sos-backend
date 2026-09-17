<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
            $table->timestamp('permissions_granted_at')->nullable()->after('permissions');
            $table->foreignId('permissions_granted_by')->nullable()->constrained('users')->onDelete('set null')->after('permissions_granted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['permissions_granted_by']);
            $table->dropColumn(['permissions', 'permissions_granted_at', 'permissions_granted_by']);
        });
    }
};
