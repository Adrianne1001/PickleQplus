<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dupr_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('play_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('match_count');
            $table->string('file_path');
            $table->timestamps();
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->foreignId('dupr_export_id')->nullable()->constrained('dupr_exports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dupr_export_id');
        });

        Schema::dropIfExists('dupr_exports');
    }
};
