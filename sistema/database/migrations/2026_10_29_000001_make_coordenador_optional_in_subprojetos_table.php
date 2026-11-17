<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E4 do teste completo: quando o coordenador é inativado ou muda de perfil, os sub-projetos
     * em andamento dele ficam sem coordenador ("a definir pela administração"), como o Projeto Base.
     */
    public function up(): void
    {
        Schema::table('subprojetos', function (Blueprint $table) {
            $table->foreignId('coordenador_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subprojetos', function (Blueprint $table) {
            $table->foreignId('coordenador_id')->nullable(false)->change();
        });
    }
};
