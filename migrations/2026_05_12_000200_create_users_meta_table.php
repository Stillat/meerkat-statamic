<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Stillat\Meerkat\Database\Migration;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('users_meta')) {
            return;
        }

        Schema::create('users_meta', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->unique('meerkat_users_meta_user_id_unique');
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('users_meta');
    }
};
