<?php

namespace App\Http\Controllers;

use App\Models\ProjetoBase;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fase 9: entrega a imagem do Projeto Base e a foto de perfil, que ficam fora da pasta pública.
 * O endereço muda quando a imagem é trocada (parâmetro "v"), então o navegador pode guardar a cópia.
 */
class ImagemController extends Controller
{
    /**
     * A imagem de um projeto da página pública é aberta a todos; a dos outros segue a ProjetoBasePolicy.
     */
    public function projeto(Request $request, ProjetoBase $projetoBase): StreamedResponse
    {
        $publica = $projetoBase->ativo && $projetoBase->publico;

        abort_unless($publica || $request->user()?->can('view', $projetoBase), 404);

        return $this->responder($projetoBase->imagem, $publica ? 'public' : 'private');
    }

    /**
     * A foto de perfil só aparece dentro do sistema, para usuários logados (LGPD).
     */
    public function foto(Usuario $usuario): StreamedResponse
    {
        return $this->responder($usuario->foto, 'private');
    }

    private function responder(?string $caminho, string $cache): StreamedResponse
    {
        abort_unless($caminho && Storage::disk('local')->exists($caminho), 404);

        return Storage::disk('local')->response($caminho, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => "{$cache}, max-age=2592000",
        ]);
    }
}
