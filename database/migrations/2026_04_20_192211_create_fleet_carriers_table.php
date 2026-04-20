<?php

use App\Models\System;
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
        // Fleet carriers are mobile — a carrier is uniquely identified by its
        // market_id, but its system_id changes as the carrier relocates.
        Schema::create('fleet_carriers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('market_id')->unique();
            $table->foreignIdFor(System::class)->constrained();
            $table->string('name');
            $table->bigInteger('distance_to_arrival')->nullable();
            $table->string('allegiance')->nullable();
            $table->string('government')->nullable();
            $table->string('economy')->nullable();
            $table->string('second_economy')->nullable();
            $table->boolean('has_market')->default(false);
            $table->boolean('has_shipyard')->default(false);
            $table->boolean('has_outfitting')->default(false);
            $table->text('other_services')->nullable();
            $table->timestamp('information_last_updated')->nullable();
            $table->timestamp('market_last_updated')->nullable();
            $table->timestamp('shipyard_last_updated')->nullable();
            $table->timestamp('outfitting_last_updated')->nullable();
            $table->string('slug')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fleet_carriers');
    }
};
