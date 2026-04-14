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
        Schema::table('systems_information', function (Blueprint $table) {
            $table->index('population');
            $table->index('allegiance');
            $table->index('government');
            $table->index('economy');
            $table->index('security');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('systems_information', function (Blueprint $table) {
            $table->dropIndex(['population']);
            $table->dropIndex(['allegiance']);
            $table->dropIndex(['government']);
            $table->dropIndex(['economy']);
            $table->dropIndex(['security']);
        });
    }
};
