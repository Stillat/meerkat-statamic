<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Stillat\Meerkat\Database\Migration;

return new class extends Migration
{
    public function up()
    {
        if ($this->hasMeerkatTable('users_meta', ['user_id', 'deleted_at'])) {
            return;
        }

        $this->schema()->create('users_meta', function (Blueprint $table) {
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
        $this->dropMeerkatTable('users_meta', ['user_id', 'deleted_at']);
    }
};
