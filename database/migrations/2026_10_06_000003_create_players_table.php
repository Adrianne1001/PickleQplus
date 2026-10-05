<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->char('dupr_id', 6)->nullable();
            $table->decimal('dupr_rating', 5, 3)->nullable();
            $table->unsignedTinyInteger('stars');
            $table->string('rating_source', 16)->default('manual');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['club_id', 'dupr_id']);
            $table->index(['club_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
