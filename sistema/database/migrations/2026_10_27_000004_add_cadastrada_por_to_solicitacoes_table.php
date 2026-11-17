<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 9: o coordenador (nos projetos que coordena) e o admin (em qualquer projeto) também abrem demandas.
     * A demanda pode ficar sem demandante, quando a comunidade/instituição não tem ninguém cadastrado no sistema;
     * `cadastrada_por` guarda quem registrou a demanda.
     */
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->foreignId('demandante_id')->nullable()->change();
            $table->foreignId('cadastrada_por')->nullable()->after('demandante_id')->constrained('usuarios')->restrictOnDelete();
        });

        // Até aqui, toda demanda foi aberta pelo próprio demandante.
        DB::table('solicitacoes')->update(['cadastrada_por' => DB::raw('demandante_id')]);
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cadastrada_por');
            $table->foreignId('demandante_id')->nullable(false)->change();
        });
    }
};
