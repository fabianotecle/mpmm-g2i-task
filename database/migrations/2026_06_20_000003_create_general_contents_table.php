<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_contents', function (Blueprint $table) {
            $table->id();
            $table->string('area');
            $table->string('key');
            $table->json('content');
            $table->json('actions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['area', 'key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_contents');
    }
};
