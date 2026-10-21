<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Token cifrado com APP_KEY: necessário apenas para montar o link seguro
            // enviado ao próprio cliente por e-mail. A busca usa manage_token_hash.
            $table->text('manage_token')->nullable()->after('manage_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('manage_token');
        });
    }
};
