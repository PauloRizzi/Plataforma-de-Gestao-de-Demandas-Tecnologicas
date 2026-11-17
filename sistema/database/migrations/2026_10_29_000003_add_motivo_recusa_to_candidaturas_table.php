<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ponto 9 do ensaio da Fase 8: a coordenação pode explicar a recusa da candidatura (opcional).
     */
    public function up(): void
    {
        Schema::table('candidaturas', function (Blueprint $table) {
            $table->string('motivo_recusa', 500)->nullable()->after('avaliado_em');
        });
    }

    public function down(): void
    {
        Schema::table('candidaturas', function (Blueprint $table) {
            $table->dropColumn('motivo_recusa');
        });
    }
};
