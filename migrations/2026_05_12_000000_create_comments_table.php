<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Stillat\Meerkat\Database\Migration;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('comments')) {
            return;
        }

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('thread_id')->index();
            $table->string('timestamp_id', 32)->nullable();
            $table->unique(['thread_id', 'timestamp_id'], 'meerkat_comments_thread_timestamp_unique');
            $table->string('author_id')->nullable();
            $table->string('site')->index();
            $table->string('user_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('referer')->nullable();
            $table->string('collection')->index();
            $table->boolean('is_published')->index();
            $table->boolean('checked_for_spam')->index();
            $table->boolean('is_spam')->index();
            $table->boolean('is_ham');
            $table->boolean('is_removed')->default(false)->index();
            $table->timestamp('removed_at')->nullable();
            $table->string('removed_by')->nullable();
            $table->string('removed_reason')->nullable();
            $table->string('moderation_status')->default('approved')->index();
            $table->string('moderation_reason')->nullable();
            $table->text('moderation_notes')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderated_by')->nullable()->index();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->integer('depth');
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->integer('replies_count')->default(0);
            $table->json('comment_data');
            $table->longText('comment_text');
            $table->string('path', 512)->index()->nullable();
            $table->text('visual_path')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['thread_id', 'is_published', 'parent_id'], 'meerkat_comments_thread_publish_parent_idx');
            $table->index(['thread_id', 'created_at'], 'meerkat_comments_thread_created_idx');
            $table->index(['author_id', 'created_at'], 'meerkat_comments_author_created_idx');
            $table->index(['site', 'collection', 'created_at'], 'meerkat_comments_site_collection_created_idx');
            $table->index(['thread_id', 'moderation_status'], 'meerkat_comments_thread_moderation_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('comments');
    }
};
