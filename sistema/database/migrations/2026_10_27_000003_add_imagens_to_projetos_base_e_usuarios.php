<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 9: imagem do Projeto Base e foto de perfil do usuário.
     * Guardam o caminho do arquivo no disco "local"; sem arquivo, a tela usa a imagem padrão.
     */
    public function up(): void
    {
        Schema::table('projetos_base', function (Blueprint $table) {
            $table->string('imagem', 255)->nullable()->after('escopo_preliminar');
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('foto', 255)->nullable()->after('telefone');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('foto');
        });

        Schema::table('projetos_base', function (Blueprint $table) {
            $table->dropColumn('imagem');
        });
    }
};
