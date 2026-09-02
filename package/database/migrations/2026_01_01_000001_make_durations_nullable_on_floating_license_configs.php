<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durations are optional: a null lease means allocations never expire and
     * a null idle timeout means no idle reclamation.
     */
    public function up(): void
    {
        Schema::table('floating_license_configs', function (Blueprint $table) {
            $table->integer('lease_duration_minutes')->unsigned()->nullable()->default(null)->change();
            $table->integer('idle_timeout_minutes')->unsigned()->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('floating_license_configs', function (Blueprint $table) {
            $table->integer('lease_duration_minutes')->unsigned()->nullable(false)->default(120)->change();
            $table->integer('idle_timeout_minutes')->unsigned()->nullable(false)->default(60)->change();
        });
    }
};
