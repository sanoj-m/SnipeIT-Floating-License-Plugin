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
     */
    public function up(): void
    {
        Schema::create('floating_license_allocations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('license_id')->unsigned()->nullable()->index();
            $table->integer('user_id')->unsigned()->nullable()->index();
            $table->integer('asset_id')->unsigned()->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->decimal('allocated_cost', 12, 2)->nullable();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('floating_license_allocations');
    }
};
