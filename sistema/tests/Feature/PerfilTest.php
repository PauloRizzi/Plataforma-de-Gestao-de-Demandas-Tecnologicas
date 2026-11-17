<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Enums\StatusUsuario;
use App\Models\Curso;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fase 9: "Meu perfil" — cada usuário edita os próprios dados, a foto e a senha.
 * Sem foto, aparece o avatar padrão do perfil.
 */
class PerfilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function perfis(): array
    {
        return [
            'admin' => ['admin'],
            'coordenador' => ['coordenador'],
            'demandante' => ['demandante'],
            'estudante' => ['estudante'],
        ];
    }

    #[DataProvider('perfis')]
    public function test_cada_perfil_abre_o_meu_perfil_com_o_avatar_padrao_do_perfil(string $perfil): void
    {
        $usuario = Usuario::factory()->{$perfil}()->create();
        $avatar = asset("images/avatares/{$perfil}.svg");

        $this->assertFileExists(public_path("images/avatares/{$perfil}.svg"));
        $this->assertSame($avatar, $usuario->urlFoto());

        $this->actingAs($usuario)->get('/perfil')
            ->assertOk()
            ->assertSee('Meu perfil')
            ->assertSee($avatar, false)
            ->assertSee($usuario->email);

        // O menu de todas as telas mostra a foto e o link para o perfil.
        $this->actingAs($usuario)->get('/painel')
            ->assertSee($avatar, false)
            ->assertSee(route('perfil.edit'), false);
    }

    public function test_visitante_e_conta_pendente_nao_abrem_o_meu_perfil(): void
    {
        $this->get('/perfil')->assertRedirect('/login');

        $pendente = Usuario::factory()->demandante()->pendente()->create();
        $this->actingAs($pendente)->get('/perfil')->assertRedirect();
        $this->actingAs($pendente)->patch('/perfil', ['nome' => 'Outro nome', 'telefone' => '(28) 98888-7777', 'cargo' => 'Diretora'])->assertRedirect();

        $this->assertNotSame('Outro nome', $pendente->refresh()->nome);
    }

    public function test_estudante_edita_nome_telefone_e_curso_mas_nao_o_que_identifica_a_conta(): void
    {
        $estudante = Usuario::factory()->estudante()->create();
        $email = $estudante->email;
        $matricula = $estudante->estudante->matricula;
        $curso = Curso::factory()->create(['nome' => 'Sistemas de Informação']);

        $this->actingAs($estudante)->patch('/perfil', [
            'nome' => 'Ana Souza',
            'telefone' => '(28) 99999-1234',
            'curso_id' => $curso->id,
            // Campos que não podem ser trocados por aqui: são ignorados.
            'email' => 'outro@edu.ufes.br',
            'matricula' => '2099000000',
            'perfil' => Perfil::Admin->value,
            'status' => StatusUsuario::Inativo->value,
        ])->assertRedirect('/perfil')->assertSessionHasNoErrors();

        $estudante->refresh();
        $this->assertSame('Ana Souza', $estudante->nome);
        $this->assertSame('(28) 99999-1234', $estudante->telefone);
        $this->assertSame($curso->id, $estudante->estudante->curso_id);
        $this->assertSame($email, $estudante->email);
        $this->assertSame($matricula, $estudante->estudante->matricula);
        $this->assertSame(Perfil::Estudante, $estudante->perfil);
        $this->assertSame(StatusUsuario::Ativo, $estudante->status);
    }

    public function test_estudante_nao_escolhe_curso_inativo(): void
    {
        $estudante = Usuario::factory()->estudante()->create();
        $inativo = Curso::factory()->create(['ativo' => false]);

        $this->actingAs($estudante)
            ->patch('/perfil', ['nome' => $estudante->nome, 'curso_id' => $inativo->id])
            ->assertSessionHasErrors('curso_id');
    }

    public function test_demandante_edita_o_cargo_e_o_telefone_continua_obrigatorio(): void
    {
        $demandante = Usuario::factory()->demandante()->create();
        $escola = $demandante->demandante->escola_instituicao_id;

        $this->actingAs($demandante)
            ->patch('/perfil', ['nome' => $demandante->nome, 'telefone' => '', 'cargo' => 'Coordenadora pedagógica'])
            ->assertSessionHasErrors('telefone');

        $this->actingAs($demandante)
            ->patch('/perfil', ['nome' => $demandante->nome, 'telefone' => '(28) 3555-0101', 'cargo' => 'Coordenadora pedagógica', 'escola_instituicao_id' => 999])
            ->assertSessionHasNoErrors();

        $demandante->refresh();
        $this->assertSame('Coordenadora pedagógica', $demandante->demandante->cargo);
        $this->assertSame('(28) 3555-0101', $demandante->telefone);
        $this->assertSame($escola, $demandante->demandante->escola_instituicao_id);
    }

    public function test_coordenador_edita_siape_e_departamento_sem_repetir_o_siape_de_outro(): void
    {
        $coordenador = Usuario::factory()->coordenador()->create();
        Usuario::factory()->coordenador()->create()->coordenador->update(['siape' => '1234567']);

        $this->actingAs($coordenador)
            ->patch('/perfil', ['nome' => $coordenador->nome, 'siape' => '1234567'])
            ->assertSessionHasErrors('siape');

        $this->actingAs($coordenador)
            ->patch('/perfil', ['nome' => $coordenador->nome, 'siape' => '7654321', 'departamento' => 'Departamento de Computação'])
            ->assertSessionHasNoErrors();

        $this->assertSame('7654321', $coordenador->coordenador->refresh()->siape);
        $this->assertSame('Departamento de Computação', $coordenador->coordenador->departamento);
    }

    public function test_foto_enviada_vira_quadrado_pequeno_e_remover_volta_ao_avatar(): void
    {
        $usuario = Usuario::factory()->demandante()->create();

        $this->actingAs($usuario)
            ->post('/perfil/foto', ['foto' => UploadedFile::fake()->image('eu.jpg', 1200, 900)])
            ->assertRedirect('/perfil')
            ->assertSessionHasNoErrors();

        $foto = $usuario->refresh()->foto;
        $this->assertStringStartsWith("fotos/{$usuario->id}/", $foto);
        [$largura, $altura, $tipo] = getimagesizefromstring(Storage::disk('local')->get($foto));
        $this->assertSame([400, 400, IMAGETYPE_JPEG], [$largura, $altura, $tipo]);

        $this->actingAs($usuario)->get('/painel')->assertSee($usuario->urlFoto(), false);

        // Trocar apaga a foto anterior.
        $this->actingAs($usuario)->post('/perfil/foto', ['foto' => UploadedFile::fake()->image('nova.png', 300, 300)]);
        Storage::disk('local')->assertMissing($foto);

        $this->actingAs($usuario)->delete('/perfil/foto')->assertRedirect('/perfil');

        $this->assertNull($usuario->refresh()->foto);
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertSame(asset('images/avatares/demandante.svg'), $usuario->urlFoto());
    }

    public function test_foto_que_nao_e_imagem_e_recusada(): void
    {
        $usuario = Usuario::factory()->estudante()->create();

        $this->actingAs($usuario)->post('/perfil/foto', [])->assertSessionHasErrors('foto');
        $this->actingAs($usuario)
            ->post('/perfil/foto', ['foto' => UploadedFile::fake()->createWithContent('eu.jpg', '<?php system($_GET["c"]); ?>')])
            ->assertSessionHasErrors('foto');

        $this->assertNull($usuario->refresh()->foto);
    }

    public function test_foto_so_abre_para_quem_esta_logado(): void
    {
        $usuario = Usuario::factory()->estudante()->create();
        $usuario->trocarFoto(UploadedFile::fake()->image('eu.jpg', 500, 500));

        $this->get($usuario->urlFoto())->assertRedirect('/login');

        $this->actingAs(Usuario::factory()->coordenador()->create())
            ->get($usuario->urlFoto())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        // Usuário sem foto: não há arquivo; as telas usam o avatar.
        $this->actingAs($usuario)->get(route('usuarios.foto', Usuario::factory()->create()))->assertNotFound();
    }

    public function test_troca_de_senha_exige_a_senha_atual(): void
    {
        $usuario = Usuario::factory()->coordenador()->create();

        $this->actingAs($usuario)
            ->put('/perfil/senha', ['senha_atual' => 'errada123', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertSessionHasErrorsIn('senha', 'senha_atual');

        $this->actingAs($usuario)
            ->put('/perfil/senha', ['senha_atual' => 'password', 'password' => 'curta', 'password_confirmation' => 'curta'])
            ->assertSessionHasErrorsIn('senha', 'password');

        $this->assertTrue(Hash::check('password', $usuario->refresh()->password));

        $this->actingAs($usuario)
            ->put('/perfil/senha', ['senha_atual' => 'password', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
            ->assertRedirect('/perfil')
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('NovaSenha123', $usuario->refresh()->password));
    }

    public function test_avatar_padrao_acompanha_a_troca_de_perfil_feita_pelo_admin(): void
    {
        $usuario = Usuario::factory()->estudante()->create();
        $this->assertSame(asset('images/avatares/estudante.svg'), $usuario->urlFoto());

        $this->actingAs(Usuario::factory()->admin()->create())
            ->patch("/admin/usuarios/{$usuario->id}/perfil", ['perfil' => Perfil::Coordenador->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(asset('images/avatares/coordenador.svg'), $usuario->refresh()->urlFoto());
    }
}
