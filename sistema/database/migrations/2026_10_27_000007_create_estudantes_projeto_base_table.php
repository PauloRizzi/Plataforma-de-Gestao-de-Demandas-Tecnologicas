<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 9: estudantes extensionistas vinculados ao Projeto Base. Só os vinculados podem ser
     * colocados pela coordenação nas equipes dos sub-projetos do projeto.
     */
    public function up(): void
    {
        Schema::create('estudantes_projeto_base', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_base_id')->constrained('projetos_base')->cascadeOnDelete();
            $table->foreignId('estudante_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['projeto_base_id', 'estudante_id']);
        });

        // Quem já participou de uma equipe fica vinculado ao projeto daquele sub-projeto.
        $vinculos = DB::table('membros_equipe')
            ->join('subprojetos', 'subprojetos.id', '=', 'membros_equipe.subprojeto_id')
            ->join('solicitacoes', 'solicitacoes.id', '=', 'subprojetos.solicitacao_id')
            ->select('solicitacoes.projeto_base_id', 'membros_equipe.estudante_id')
            ->distinct()
            ->get()
            ->map(fn ($vinculo) => [
                'projeto_base_id' => $vinculo->projeto_base_id,
                'estudante_id' => $vinculo->estudante_id,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        DB::table('estudantes_projeto_base')->insert($vinculos);
    }

    public function down(): void
    {
        Schema::dropIfExists('estudantes_projeto_base');
    }
};
