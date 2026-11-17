<?php

namespace Tests\Feature;

use App\Enums\StatusDemanda;
use App\Enums\StatusUsuario;
use App\Models\MembroEquipe;
use App\Models\PapelEquipe;
use App\Models\ProjetoBase;
use App\Models\Solicitacao;
use App\Models\Subprojeto;
use App\Models\Tipologia;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 9: estudantes extensionistas vinculados ao Projeto Base e filtro da lista de sub-projetos.
 */
class ProjetoVinculoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $coordenador;

    private ProjetoBase $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordenador = Usuario::factory()->coordenador()->create();
        $this->projeto = ProjetoBase::factory()->create(['coordenador_id' => $this->coordenador->id, 'titulo' => 'Robótica nas Escolas']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosDoProjeto(array $extra = []): array
    {
        return array_merge([
            'titulo' => $this->projeto->titulo,
            'descricao' => 'Apoio tecnológico às escolas da região.',
            'data_inicio' => '2026-11-01',
            'tipologia_id' => Tipologia::firstOrFail()->id,
            'publico' => '1',
            'ativo' => '1',
        ], $extra);
    }

    public function test_coordenador_vincula_e_desvincula_estudantes_no_projeto(): void
    {
        $ana = Usuario::factory()->estudante()->create(['nome' => 'Ana Extensionista']);
        $bruno = Usuario::factory()->estudante()->create(['nome' => 'Bruno Extensionista']);
        $pendente = Usuario::factory()->estudante()->create(['status' => StatusUsuario::Pendente]);
        $demandante = Usuario::factory()->demandante()->create();

        $this->actingAs($this->coordenador)->get("/projetos-base/{$this->projeto->id}/edit")
            ->assertOk()
            ->assertSee('Estudantes extensionistas vinculados')
            ->assertSee('Ana Extensionista');

        $this->actingAs($this->coordenador)
            ->put("/projetos-base/{$this->projeto->id}", $this->dadosDoProjeto(['estudantes' => [$ana->id, $bruno->id]]))
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$ana->id, $bruno->id], $this->projeto->estudantes()->pluck('usuarios.id')->all());

        // Sem o campo no envio (ex.: outro formulário), os vínculos ficam como estão.
        $this->actingAs($this->coordenador)->put("/projetos-base/{$this->projeto->id}", $this->dadosDoProjeto())->assertSessionHasNoErrors();
        $this->assertSame(2, $this->projeto->estudantes()->count());

        // Desmarcar desvincula; campo vazio (ninguém marcado) desvincula todos.
        $this->actingAs($this->coordenador)
            ->put("/projetos-base/{$this->projeto->id}", $this->dadosDoProjeto(['estudantes' => [$ana->id]]))
            ->assertSessionHasNoErrors();
        $this->assertSame([$ana->id], $this->projeto->estudantes()->pluck('usuarios.id')->all());

        $this->actingAs($this->coordenador)
            ->put("/projetos-base/{$this->projeto->id}", $this->dadosDoProjeto(['estudantes' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $this->projeto->estudantes()->count());

        // Só estudantes ativos.
        $this->actingAs($this->coordenador)
            ->put("/projetos-base/{$this->projeto->id}", $this->dadosDoProjeto(['estudantes' => [$pendente->id, $demandante->id]]))
            ->assertSessionHasErrors(['estudantes.0', 'estudantes.1']);
    }

    public function test_admin_vincula_estudantes_ao_cadastrar_o_projeto(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $ana = Usuario::factory()->estudante()->create();

        $this->actingAs($admin)->post('/projetos-base', $this->dadosDoProjeto([
            'titulo' => 'Projeto Novo',
            'coordenador_id' => $this->coordenador->id,
            'estudantes' => [$ana->id],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([$ana->id], ProjetoBase::where('titulo', 'Projeto Novo')->sole()->estudantes()->pluck('usuarios.id')->all());
    }

    public function test_so_admin_e_coordenador_do_projeto_veem_a_lista_e_o_estudante_ve_o_proprio_vinculo(): void
    {
        $ana = Usuario::factory()->estudante()->create(['nome' => 'Ana Extensionista']);
        $bruno = Usuario::factory()->estudante()->create(['nome' => 'Bruno Sem Vinculo']);
        $this->projeto->estudantes()->attach($ana);

        $this->actingAs($this->coordenador)->get("/projetos-base/{$this->projeto->id}")
            ->assertSee('Estudantes extensionistas vinculados (1)')
            ->assertSee('Ana Extensionista');
        $this->actingAs(Usuario::factory()->admin()->create())->get("/projetos-base/{$this->projeto->id}")
            ->assertSee('Ana Extensionista');

        $this->actingAs(Usuario::factory()->coordenador()->create())->get("/projetos-base/{$this->projeto->id}")
            ->assertOk()->assertDontSee('Ana Extensionista');
        $this->actingAs(Usuario::factory()->demandante()->create())->get("/projetos-base/{$this->projeto->id}")
            ->assertOk()->assertDontSee('Ana Extensionista');
        $this->get("/projetos/{$this->projeto->id}")->assertOk()->assertDontSee('Ana Extensionista');

        // O estudante vê só o próprio vínculo, sem a lista dos colegas.
        $this->actingAs($ana)->get("/projetos-base/{$this->projeto->id}")
            ->assertSee('Você é estudante extensionista deste projeto.')
            ->assertDontSee('Estudantes extensionistas vinculados');
        $this->actingAs($ana)->get('/subprojetos')->assertSee('Meus projetos de extensão')->assertSee('Robótica nas Escolas');

        $this->actingAs($bruno)->get("/projetos-base/{$this->projeto->id}")->assertDontSee('Você é estudante extensionista deste projeto.');
        $this->actingAs($bruno)->get('/subprojetos')->assertSee('Você ainda não está vinculado a nenhum projeto de extensão.');
    }

    public function test_desvincular_nao_tira_o_estudante_da_equipe(): void
    {
        $ana = Usuario::factory()->estudante()->create();
        $this->projeto->estudantes()->attach($ana);
        $solicitacao = Solicitacao::factory()->triada()->comStatus(StatusDemanda::EmDesenvolvimento)->create(['projeto_base_id' => $this->projeto->id]);
        $subprojeto = Subprojeto::factory()->create(['solicitacao_id' => $solicitacao->id]);
        MembroEquipe::create(['subprojeto_id' => $subprojeto->id, 'estudante_id' => $ana->id, 'papel_id' => PapelEquipe::firstOrFail()->id]);

        $this->actingAs($this->coordenador)
            ->put("/projetos-base/{$this->projeto->id}", $this->dadosDoProjeto(['estudantes' => '']))
            ->assertSessionHasNoErrors();

        $this->assertTrue($subprojeto->ehMembroAtivo($ana));
    }

    public function test_vitrine_e_candidatura_so_para_estudantes_vinculados(): void
    {
        $vinculada = Usuario::factory()->estudante()->create();
        $semVinculo = Usuario::factory()->estudante()->create();
        $this->projeto->estudantes()->attach($vinculada);
        $solicitacao = Solicitacao::factory()->triada()->comStatus(StatusDemanda::Priorizada)->create(['projeto_base_id' => $this->projeto->id]);
        $subprojeto = Subprojeto::factory()->create(['solicitacao_id' => $solicitacao->id, 'titulo' => 'Oficina de Arduino', 'vagas' => 3]);

        $this->actingAs($vinculada)->get('/oportunidades')->assertSee('Oficina de Arduino');
        $this->actingAs($semVinculo)->get('/oportunidades')
            ->assertDontSee('Oficina de Arduino')
            ->assertSee('Você ainda não está vinculado a nenhum projeto de extensão.');

        // Pelo link da página pública, a vaga abre, mas a inscrição explica o motivo e é recusada.
        $this->actingAs($semVinculo)->get("/oportunidades/{$subprojeto->id}")
            ->assertOk()
            ->assertSee('Só os estudantes vinculados ao projeto de extensão');
        $this->actingAs($semVinculo)->post("/oportunidades/{$subprojeto->id}/candidatura")->assertForbidden();

        $this->actingAs($vinculada)->post("/oportunidades/{$subprojeto->id}/candidatura")->assertRedirect();
        $this->assertSame(1, $subprojeto->candidaturas()->count());
    }

    public function test_candidatura_de_quem_foi_desvinculado_nao_e_aprovada(): void
    {
        $estudante = Usuario::factory()->estudante()->create(['nome' => 'Davi Desvinculado']);
        $solicitacao = Solicitacao::factory()->triada()->comStatus(StatusDemanda::Priorizada)->create(['projeto_base_id' => $this->projeto->id]);
        $subprojeto = Subprojeto::factory()->create(['solicitacao_id' => $solicitacao->id, 'vagas' => 3]);
        $candidatura = $subprojeto->candidaturas()->create(['estudante_id' => $estudante->id]);

        $this->actingAs($this->coordenador)->get("/subprojetos/{$subprojeto->id}")
            ->assertSee('Davi Desvinculado')
            ->assertSee('Não vinculado(a) ao projeto');

        $this->actingAs($this->coordenador)
            ->patch("/subprojetos/{$subprojeto->id}/candidaturas/{$candidatura->id}/aprovar", ['papel_id' => PapelEquipe::firstOrFail()->id])
            ->assertSessionHasErrors(['candidatura' => 'Davi Desvinculado não está vinculado(a) ao projeto de extensão. Vincule no cadastro do projeto ou recuse a candidatura.']);

        $this->assertFalse($subprojeto->ehMembroAtivo($estudante));
    }

    public function test_lista_de_sub_projetos_tem_busca_e_filtros(): void
    {
        $outroProjeto = ProjetoBase::factory()->create(['coordenador_id' => $this->coordenador->id, 'titulo' => 'Conecta Escola']);
        Subprojeto::factory()->create([
            'titulo' => 'Site da escola',
            'modalidade' => 'remoto',
            'solicitacao_id' => Solicitacao::factory()->triada()->comStatus(StatusDemanda::EmDesenvolvimento)->create(['projeto_base_id' => $this->projeto->id])->id,
        ]);
        Subprojeto::factory()->create([
            'titulo' => 'Rede do laboratório',
            'modalidade' => 'presencial',
            'solicitacao_id' => Solicitacao::factory()->triada()->comStatus(StatusDemanda::Concluida)->create(['projeto_base_id' => $outroProjeto->id])->id,
        ]);

        $this->actingAs($this->coordenador)->get('/subprojetos')
            ->assertOk()->assertSee('Site da escola')->assertSee('Rede do laboratório');

        $this->actingAs($this->coordenador)->get('/subprojetos?busca=site')
            ->assertSee('Site da escola')->assertDontSee('Rede do laboratório');
        $this->actingAs($this->coordenador)->get("/subprojetos?projeto={$outroProjeto->id}")
            ->assertSee('Rede do laboratório')->assertDontSee('Site da escola');
        $this->actingAs($this->coordenador)->get('/subprojetos?status=em_desenvolvimento')
            ->assertSee('Site da escola')->assertDontSee('Rede do laboratório');
        $this->actingAs($this->coordenador)->get('/subprojetos?modalidade=presencial')
            ->assertSee('Rede do laboratório')->assertDontSee('Site da escola');
        $this->actingAs($this->coordenador)->get('/subprojetos?busca=inexistente')
            ->assertSee('Nenhum sub-projeto encontrado com esses filtros.');

        // O filtro não mostra sub-projetos de outro coordenador.
        $this->actingAs(Usuario::factory()->coordenador()->create())->get("/subprojetos?projeto={$this->projeto->id}")
            ->assertDontSee('Site da escola');
    }
}
