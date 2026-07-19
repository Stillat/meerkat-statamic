<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Stillat\Meerkat\Database\Migration;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('thread_metrics')) {
            return;
        }

        Schema::create('thread_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('thread_id')->unique();
            $table->string('site')->nullable()->index();
            $table->string('collection')->nullable()->index();
            $table->unsignedInteger('total_comments')->default(0);
            $table->unsignedInteger('published_comments')->default(0);
            $table->unsignedInteger('pending_comments')->default(0);
            $table->unsignedInteger('spam_comments')->default(0);
            $table->unsignedInteger('root_comments')->default(0);
            $table->unsignedInteger('reply_comments')->default(0);
            $table->unsignedInteger('participants')->default(0);
            $table->unsignedInteger('max_depth')->default(0);
            $table->timestamp('first_comment_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('thread_metrics');
    }
};
