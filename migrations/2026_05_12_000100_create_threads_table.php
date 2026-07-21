<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Stillat\Meerkat\Database\Migration;

return new class extends Migration
{
    public function up()
    {
        if ($this->hasMeerkatTable('threads', ['thread_id', 'cached_title'])) {
            return;
        }

        $this->schema()->create('threads', function (Blueprint $table) {
            $table->id();
            $table->string('thread_id')->unique('meerkat_threads_thread_id_unique');
            $table->string('entry_id')->nullable()->index();
            $table->string('site')->nullable()->index();
            $table->string('collection')->nullable()->index();
            $table->string('cached_title');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        $this->dropMeerkatTable('threads', ['thread_id', 'cached_title']);
    }
};
