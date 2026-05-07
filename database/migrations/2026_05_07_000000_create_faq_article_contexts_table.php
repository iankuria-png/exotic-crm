<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_article_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('faq_articles')->cascadeOnDelete();
            $table->string('crm_page', 40);
            $table->string('surface', 40)->default('help_drawer');
            $table->string('context_kind', 20)->default('script');
            $table->unsignedInteger('priority')->default(100);
            $table->timestamps();

            $table->unique(['article_id', 'crm_page', 'surface']);
            $table->index(['crm_page', 'surface', 'context_kind', 'priority'], 'faq_article_contexts_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_article_contexts');
    }
};
