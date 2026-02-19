<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');
            $table->string('domain')->unique();
            $table->string('country');
            $table->boolean('is_active')->default(true);
            $table->string('db_host')->nullable();
            $table->string('db_name')->nullable();  
            $table->string('db_user')->nullable();
            $table->string('db_pass')->nullable();
            $table->string('db_prefix')->nullable();
            $table->timestamps();
    
        });
    }


    
    public function down(): void
    {
        Schema::dropIfExists('platforms');
    }
};
