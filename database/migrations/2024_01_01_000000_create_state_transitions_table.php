<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('state_transitions', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->string('field');
            $table->string('from');
            $table->string('to');
            $table->json('metadata')->nullable();
            $table->timestamp('transitioned_at');
            $table->timestamp('created_at');

            $table->index(['model_type', 'model_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('state_transitions');
    }
};
