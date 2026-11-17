<?php

namespace App\Http\Controllers;

use App\Enums\Perfil;
use App\Models\Curso;
use App\Models\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Fase 9: "Meu perfil". Cada usuário edita os próprios dados, a foto e a senha.
 *
 * Ficam de fora o que identifica a conta ou depende de conferência do admin:
 * e-mail (login), perfil, status, matrícula do estudante e comunidade/instituição do demandante.
 */
class PerfilController extends Controller
{
    public function edit(Request $request): View
    {
        $usuario = $request->user()->load(['estudante.curso', 'demandante.escolaInstituicao', 'coordenador']);

        $cursos = Curso::query()->where('ativo', true)->orderBy('nome')->get();

        // Mantém na lista o curso atual, mesmo que o admin o tenha inativado depois.
        if ($usuario->estudante && ! $cursos->contains('id', $usuario->estudante->curso_id)) {
            $cursos->push($usuario->estudante->curso);
        }

        return view('perfil.edit', [
            'usuario' => $usuario,
            'cursos' => $cursos,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $dados = $request->validate($this->regras($usuario), [
            'telefone.regex' => 'Informe o telefone no formato (28) 99999-9999.',
            'siape.unique' => 'Este SIAPE já está cadastrado.',
        ], [
            'curso_id' => 'curso',
            'siape' => 'SIAPE',
        ]);

        DB::transaction(function () use ($usuario, $dados): void {
            $usuario->update([
                'nome' => $dados['nome'],
                'telefone' => $dados['telefone'] ?? null,
            ]);

            match (true) {
                $usuario->temPerfil(Perfil::Estudante) && $usuario->estudante !== null => $usuario->estudante->update(['curso_id' => $dados['curso_id']]),
                $usuario->temPerfil(Perfil::Demandante) && $usuario->demandante !== null => $usuario->demandante->update(['cargo' => $dados['cargo']]),
                $usuario->temPerfil(Perfil::Coordenador) && $usuario->coordenador !== null => $usuario->coordenador->update([
                    'siape' => $dados['siape'] ?? null,
                    'departamento' => $dados['departamento'] ?? null,
                ]),
                default => null,
            };
        });

        return redirect()->route('perfil.edit')->with('sucesso', 'Seus dados foram atualizados.');
    }

    public function atualizarFoto(Request $request): RedirectResponse
    {
        $request->validate([
            'foto' => ['required', ...Usuario::regrasDaImagem()],
        ], [
            'foto.required' => 'Escolha uma foto.',
            'foto.mimes' => 'A foto precisa ser JPG, PNG ou WebP.',
            'foto.dimensions' => 'A foto precisa ter entre 100 e 6000 pixels de largura e de altura.',
        ]);

        $request->user()->trocarFoto($request->file('foto'));

        return redirect()->route('perfil.edit')->with('sucesso', 'Foto de perfil atualizada.');
    }

    public function removerFoto(Request $request): RedirectResponse
    {
        $request->user()->removerFoto();

        return redirect()->route('perfil.edit')->with('sucesso', 'Foto removida. Agora aparece o avatar padrão do seu perfil.');
    }

    public function atualizarSenha(Request $request): RedirectResponse
    {
        $dados = $request->validateWithBag('senha', [
            'senha_atual' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'senha_atual.current_password' => 'A senha atual não confere.',
        ], [
            'senha_atual' => 'senha atual',
            'password' => 'nova senha',
        ]);

        $request->user()->update(['password' => $dados['password']]);

        return redirect()->route('perfil.edit')->with('sucesso', 'Senha alterada.');
    }

    /**
     * Campos comuns a todos e os do perfil do usuário.
     *
     * @return array<string, array<mixed>>
     */
    private function regras(Usuario $usuario): array
    {
        $telefone = ['regex:/^\(\d{2}\) \d{4,5}-\d{4}$/'];

        return [
            'nome' => ['required', 'string', 'max:150'],
            // O telefone é obrigatório para o demandante desde o cadastro (contato da comunidade/instituição).
            'telefone' => [$usuario->temPerfil(Perfil::Demandante) ? 'required' : 'nullable', ...$telefone],
            ...match (true) {
                $usuario->temPerfil(Perfil::Estudante) && $usuario->estudante !== null => [
                    'curso_id' => ['required', Rule::exists('cursos', 'id')->where(
                        fn ($query) => $query->where('ativo', true)->orWhere('id', $usuario->estudante->curso_id)
                    )],
                ],
                $usuario->temPerfil(Perfil::Demandante) && $usuario->demandante !== null => [
                    'cargo' => ['required', 'string', 'max:100'],
                ],
                $usuario->temPerfil(Perfil::Coordenador) && $usuario->coordenador !== null => [
                    'siape' => ['nullable', 'digits_between:5,20', Rule::unique('coordenadores', 'siape')->ignore($usuario->id, 'usuario_id')],
                    'departamento' => ['nullable', 'string', 'max:150'],
                ],
                default => [],
            },
        ];
    }
}
