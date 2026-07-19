<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Stillat\Meerkat\Database\Migration;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('comment_revisions')) {
            return;
        }

        Schema::create('comment_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('comment_id');
            $table->unsignedInteger('revision_number');
            $table->text('comment_text');
            $table->json('comment_data')->nullable();
            $table->string('edited_by')->nullable();
            $table->string('edit_reason')->nullable();
            $table->timestamp('edited_at')->index();
            $table->timestamps();

            $table->index(['comment_id', 'revision_number']);
            $table->unique(['comment_id', 'revision_number']);

            $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('comment_revisions');
    }
};
