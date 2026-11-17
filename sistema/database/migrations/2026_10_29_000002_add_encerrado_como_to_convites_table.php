<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E7 do teste completo: o convite encerrado antes do prazo guarda o motivo ("cancelado" pelo admin
     * ou "substituido" por um convite novo), para a lista não mostrar tudo como "Expirado".
     */
    public function up(): void
    {
        Schema::table('convites', function (Blueprint $table) {
            $table->string('encerrado_como', 15)->nullable()->after('usado_em');
        });
    }

    public function down(): void
    {
        Schema::table('convites', function (Blueprint $table) {
            $table->dropColumn('encerrado_como');
        });
    }
};
