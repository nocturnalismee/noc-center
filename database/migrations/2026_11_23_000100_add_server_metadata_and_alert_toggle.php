<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->renameColumn('group_name', 'server_type');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('provider', 100)->nullable();
            $table->string('location', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('label', 100)->nullable();
            $table->boolean('alerts_enabled')->default(true);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->index('server_type');
            $table->index('provider');
            $table->index('location');
            $table->index('alerts_enabled');
        });
    }

    public function down(): void
    {
        // SQLite drops a column's indexes with the column; the explicit
        // dropIndex calls still express the intent in a dumped schema.
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['server_type']);
            $table->dropIndex(['provider']);
            $table->dropIndex(['location']);
            $table->dropIndex(['alerts_enabled']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['provider', 'location', 'ip_address', 'label', 'alerts_enabled']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->renameColumn('server_type', 'group_name');
        });
    }
};
