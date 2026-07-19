<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Stillat\Meerkat\Database\Migration;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('comment_moderation_audits')) {
            return;
        }

        Schema::create('comment_moderation_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('comment_id')->index();
            $table->string('actor_id')->nullable()->index();
            $table->string('action')->index();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('comment_moderation_audits');
    }
};
