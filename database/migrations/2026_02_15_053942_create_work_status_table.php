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
        Schema::create('work_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date')->nullable();
            $table->timestamps();
        });
        
        Schema::create('work_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_status_id')->constrained('work_status')->cascadeOnDelete();
            $table->text('work_place')->nullable();
            $table->text('worker_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_list');
        Schema::dropIfExists('work_status');
    }
};
