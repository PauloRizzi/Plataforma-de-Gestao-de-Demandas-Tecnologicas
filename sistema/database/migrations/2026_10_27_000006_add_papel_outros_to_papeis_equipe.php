<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fase 9: papel "Outros" na equipe do sub-projeto, para quem não se encaixa nos papéis iniciais.
     */
    public function up(): void
    {
        if (DB::table('papeis_equipe')->where('nome', 'Outros')->doesntExist()) {
            DB::table('papeis_equipe')->insert(['nome' => 'Outros', 'ativo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Só apaga se nenhum membro estiver usando o papel.
        DB::table('papeis_equipe')
            ->where('nome', 'Outros')
            ->whereNotExists(fn ($query) => $query->from('membros_equipe')->whereColumn('membros_equipe.papel_id', 'papeis_equipe.id'))
            ->delete();
    }
};
