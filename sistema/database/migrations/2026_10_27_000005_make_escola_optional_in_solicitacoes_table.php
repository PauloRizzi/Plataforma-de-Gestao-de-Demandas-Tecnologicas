<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 9: a demanda aberta pela coordenação pode ficar sem escola ou instituição.
     * A demanda aberta pelo demandante continua levando a escola do cadastro dele.
     */
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->foreignId('escola_instituicao_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->foreignId('escola_instituicao_id')->nullable(false)->change();
        });
    }
};
