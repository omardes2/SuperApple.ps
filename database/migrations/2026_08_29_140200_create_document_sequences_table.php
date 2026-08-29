<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type');            // customer, project, invoice...
            $table->integer('period')->nullable(); // year for year-scoped sequences
            $table->unsignedBigInteger('current')->default(0);
            $table->timestamps();

            $table->unique(['type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
