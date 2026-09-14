<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packs', function (Blueprint $table) {
            // Profils ciblés par le pack (ex: ["manufacturer","reseller"]) — null = tous les profils
            $table->json('profile_types')->nullable()->after('features');
        });
    }

    public function down(): void
    {
        Schema::table('packs', function (Blueprint $table) {
            $table->dropColumn('profile_types');
        });
    }
};
