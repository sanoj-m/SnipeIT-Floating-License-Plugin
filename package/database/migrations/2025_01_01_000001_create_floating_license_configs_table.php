<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Note: per Snipe-IT convention there are NO foreign key constraints.
     * license_id references the existing licenses table by convention only.
     */
    public function up(): void
    {
        Schema::create('floating_license_configs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('license_id')->unsigned()->unique();
            $table->integer('pool_size')->unsigned();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->string('cost_mode')->default('pool_slot');
            $table->boolean('allow_over_allocation')->default(false);
            $table->integer('lease_duration_minutes')->unsigned()->default(120);
            $table->integer('idle_timeout_minutes')->unsigned()->default(60);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('floating_license_configs');
    }
};
