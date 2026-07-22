<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('palworld_bans', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->index();
            $table->string('user_id');
            $table->string('name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('player_id')->nullable();
            $table->string('ip')->nullable();
            $table->unsignedInteger('level')->nullable();
            $table->text('message')->nullable();
            $table->string('banned_by')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('palworld_bans');
    }
};
