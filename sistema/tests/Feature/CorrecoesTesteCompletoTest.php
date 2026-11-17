<?php

namespace Tests\Feature;

use App\Enums\SituacaoEntrega;
use App\Enums\StatusCandidatura;
use App\Enums\StatusDemanda;
use App\Enums\StatusUsuario;
use App\Models\Candidatura;
use App\Models\Convite;
use App\Models\Entrega;
use App\Models\MembroEquipe;
use App\Models\PapelEquipe;
use App\Models\ProjetoBase;
use App\Models\Solicitacao;
use App\Models\Subprojeto;
use App\Models\Usuario;
use App\Notifications\Aviso;
use App\Policies\SolicitacaoPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fase 9: correções dos erros achados no teste completo da Fase 8 (E1 a E9 e pontos do ensaio).
 */
class CorrecoesTesteCompletoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $coordenador;

    private Usuario $admin;

    private ProjetoBase $projeto;

    private Solicitacao $solicitacao;

    private Subprojeto $subprojeto;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->admin = Usuario::factory()->admin()->create();
        $this->coordenador = Usuario::factory()->coordenador()->create();
        $this->projeto = ProjetoBase::factory()->create(['coordenador_id' => $this->coordenador->id]);
        $this->solicitacao = Solicitacao::factory()->triada()->comStatus(StatusDemanda::EmDesenvolvimento)->create(['projeto_base_id' => $this->projeto->id]);
        $this->subprojeto = Subprojeto::factory()->create(['solicitacao_id' => $this->solicitacao->id, 'coordenador_id' => $this->coordenador->id, 'vagas' => 3]);
    }

    private function papel(string $nome = PapelEquipe::PADRAO): PapelEquipe
    {
        return PapelEquipe::where('nome', $nome)->firstOrFail();
    }

    private function vinculado(): Usuario
    {
        $estudante = Usuario::factory()->estudante()->create();
        $this->projeto->estudantes()->attach($estudante);

        return $estudante;
    }

    private function removerDaEquipe(Usuario $estudante): void
    {
        $membro = MembroEquipe::create(['subprojeto_id' => $this->subprojeto->id, 'estudante_id' => $estudante->id, 'papel_id' => $this->papel()->id]);

        $this->actingAs($this->coordenador)->patch("/subprojetos/{$this->subprojeto->id}/membros/{$membro->id}/remover")->assertRedirect();
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosDoSubprojeto(array $extra = []): array
    {
        return [
            'titulo' => $this->subprojeto->titulo,
            'escopo' => $this->subprojeto->escopo,
            'data_inicio' => $this->subprojeto->data_inicio->format('Y-m-d'),
            'data_fim' => $this->subprojeto->data_fim->format('Y-m-d'),
            'carga_horaria_estimada' => $this->subprojeto->carga_horaria_estimada,
            'vagas' => 3,
            'modalidade' => $this->subprojeto->modalidade->value,
            'candidaturas_abertas' => '1',
            ...$extra,
        ];
    }

    public function test_e1_estudante_removido_volta_para_a_equipe_pela_edicao(): void
    {
        $estudante = $this->vinculado();
        $this->removerDaEquipe($estudante);

        $this->actingAs($this->coordenador)->get("/subprojetos/{$this->subprojeto->id}/edit")->assertSee($estudante->nome);

        $this->actingAs($this->coordenador)->put("/subprojetos/{$this->subprojeto->id}", $this->dadosDoSubprojeto([
            'estudantes' => [$estudante->id],
            'papel_id' => $this->papel('Outros')->id,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $membro = MembroEquipe::where('subprojeto_id', $this->subprojeto->id)->where('estudante_id', $estudante->id)->sole();
        $this->assertFalse($membro->foiRemovido());
        $this->assertSame($this->papel('Outros')->id, $membro->papel_id);
        $this->assertTrue($this->solicitacao->historicos()->where('descricao', "{$estudante->nome} voltou para a equipe como Outros")->exists());
        Notification::assertSentTo($estudante, Aviso::class, fn (Aviso $aviso) => str_starts_with($aviso->assunto, 'Você voltou para a equipe'));
    }

    public function test_e2_candidatura_de_quem_ja_foi_removido_e_aprovada_sem_erro(): void
    {
        $estudante = $this->vinculado();
        $this->removerDaEquipe($estudante);

        // Removido não se candidata de novo pela vitrine (decisão (e) da Fase 5)...
        $this->assertStringStartsWith('Você já participou desta equipe.', $this->subprojeto->fresh()->impedimentoDeCandidatura($estudante));
        $this->actingAs($estudante)->post("/oportunidades/{$this->subprojeto->id}/candidatura")->assertForbidden();

        // ...mas uma candidatura antiga ainda pendente é aprovada sem o erro 500.
        $candidatura = Candidatura::create(['subprojeto_id' => $this->subprojeto->id, 'estudante_id' => $estudante->id]);
        $this->actingAs($this->coordenador)
            ->patch("/subprojetos/{$this->subprojeto->id}/candidaturas/{$candidatura->id}/aprovar", ['papel_id' => $this->papel()->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $membro = MembroEquipe::where('subprojeto_id', $this->subprojeto->id)->where('estudante_id', $estudante->id)->sole();
        $this->assertFalse($membro->foiRemovido());
        $this->assertSame($candidatura->id, $membro->candidatura_id);
        Notification::assertNotSentTo($estudante, Aviso::class, fn (Aviso $aviso) => str_starts_with($aviso->assunto, 'Você voltou para a equipe'));
    }

    public function test_e9_candidatura_aprovada_de_quem_saiu_aparece_como_removido(): void
    {
        $estudante = $this->vinculado();
        $candidatura = Candidatura::create([
            'subprojeto_id' => $this->subprojeto->id,
            'estudante_id' => $estudante->id,
            'status' => StatusCandidatura::Aprovada,
        ]);
        $membro = MembroEquipe::create(['subprojeto_id' => $this->subprojeto->id, 'estudante_id' => $estudante->id, 'papel_id' => $this->papel()->id, 'candidatura_id' => $candidatura->id]);

        $this->assertSame('Aprovada', $candidatura->rotuloSituacao());

        $membro->update(['removido_em' => now()]);

        $this->assertSame('Aprovada · removido(a) da equipe', $candidatura->fresh()->rotuloSituacao());
        $this->actingAs($estudante)->get("/oportunidades/{$this->subprojeto->id}")->assertSee('Aprovada · removido(a) da equipe');
        $this->actingAs($estudante)->get('/subprojetos')->assertSee('Aprovada · removido(a) da equipe');
    }

    public function test_e3_admin_nao_cancela_demanda_concluida_nem_recusada(): void
    {
        foreach ([StatusDemanda::Concluida, StatusDemanda::Recusada] as $status) {
            $this->solicitacao->update(['status' => $status]);

            // A tela não oferece "Cancelada", e o envio forjado é recusado (negado ou com erro, conforme o status).
            $resposta = $this->actingAs($this->admin)
                ->patch("/demandas/{$this->solicitacao->id}/status", ['status' => StatusDemanda::Cancelada->value, 'motivo_status' => 'Teste']);
            $this->assertTrue($resposta->isForbidden() || session()->has('errors'));

            $this->assertSame($status, $this->solicitacao->fresh()->status);
            $this->assertNotContains(StatusDemanda::Cancelada, app(SolicitacaoPolicy::class)->statusDisponiveis($this->admin, $this->solicitacao->fresh()));
        }

        // Em andamento, o admin continua podendo cancelar.
        $this->solicitacao->update(['status' => StatusDemanda::EmDesenvolvimento]);
        $this->actingAs($this->admin)
            ->patch("/demandas/{$this->solicitacao->id}/status", ['status' => StatusDemanda::Cancelada->value, 'motivo_status' => 'A escola desistiu.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatusDemanda::Cancelada, $this->solicitacao->fresh()->status);
    }

    public function test_e4_coordenador_inativado_passa_projetos_e_sub_projetos_em_andamento_para_a_administracao(): void
    {
        $concluida = Solicitacao::factory()->triada()->comStatus(StatusDemanda::Concluida)->create(['projeto_base_id' => $this->projeto->id]);
        $encerrado = Subprojeto::factory()->create(['solicitacao_id' => $concluida->id, 'coordenador_id' => $this->coordenador->id]);

        $this->actingAs($this->admin)->get("/admin/usuarios/{$this->coordenador->id}")
            ->assertSee('responsável por 1 Projeto(s) Base e 1 sub-projeto(s) em andamento');

        $this->actingAs($this->admin)
            ->patch("/admin/usuarios/{$this->coordenador->id}/status", ['status' => StatusUsuario::Inativo->value, 'motivo_status' => 'Aposentadoria'])
            ->assertSessionHas('sucesso', fn (string $mensagem) => str_contains($mensagem, '1 Projeto(s) Base e 1 sub-projeto(s) em andamento passaram para a administração'));

        $this->assertNull($this->projeto->fresh()->coordenador_id);
        $this->assertNull($this->subprojeto->fresh()->coordenador_id);
        // A demanda concluída continua no nome dele.
        $this->assertSame($this->coordenador->id, $encerrado->fresh()->coordenador_id);
        $this->assertTrue($this->solicitacao->historicos()->where('descricao', 'like', 'A coordenação do sub-projeto passou para a administração%')->exists());

        // Os avisos da demanda passam a ir para os admins (todos os ativos).
        $outroAdmin = Usuario::factory()->admin()->create();
        $this->assertEqualsCanonicalizing([$this->admin->id, $outroAdmin->id], $this->solicitacao->fresh()->coordenacao()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->admin->id, $outroAdmin->id], $concluida->fresh()->coordenacao()->pluck('id')->all());

        // As telas mostram "a definir".
        $this->actingAs($this->admin)->get("/subprojetos/{$this->subprojeto->id}")->assertOk()->assertSee('A definir pela administração');
    }

    public function test_e4_coordenador_que_muda_de_perfil_tambem_libera_a_coordenacao(): void
    {
        $this->actingAs($this->admin)->patch("/admin/usuarios/{$this->coordenador->id}/perfil", ['perfil' => 'admin'])->assertSessionHasNoErrors();

        $this->assertNull($this->projeto->fresh()->coordenador_id);
        $this->assertNull($this->subprojeto->fresh()->coordenador_id);
    }

    public function test_e5_devolver_entrega_em_desenvolvimento_nao_manda_para_aguardando_ajustes(): void
    {
        $estudante = $this->vinculado();
        $entrega = Entrega::factory()->comSituacao(SituacaoEntrega::AguardandoTecnica)->create(['subprojeto_id' => $this->subprojeto->id, 'enviado_por' => $estudante->id]);

        $this->actingAs($this->coordenador)
            ->post("/subprojetos/{$this->subprojeto->id}/entregas/{$entrega->id}/validacao", ['decisao' => 'devolvida', 'feedback' => 'Falta o rodapé.'])
            ->assertSessionHas('sucesso', 'Entrega devolvida para a equipe. A equipe faz os ajustes e envia uma nova entrega.');

        $this->solicitacao->update(['status' => StatusDemanda::EmValidacao]);
        $outra = Entrega::factory()->comSituacao(SituacaoEntrega::AguardandoTecnica)->create(['subprojeto_id' => $this->subprojeto->id, 'enviado_por' => $estudante->id, 'numero' => 2]);
        $this->actingAs($this->coordenador)
            ->post("/subprojetos/{$this->subprojeto->id}/entregas/{$outra->id}/validacao", ['decisao' => 'devolvida', 'feedback' => 'Falta o rodapé.'])
            ->assertSessionHas('sucesso', 'Entrega devolvida para a equipe. Se for o caso, mude a demanda para "Aguardando Ajustes".');
    }

    public function test_e7_convite_cancelado_e_substituido_tem_situacao_propria(): void
    {
        $this->actingAs($this->admin)->post('/admin/convites', ['email' => 'prof@ufes.br'])->assertRedirect();
        $primeiro = Convite::sole();

        $this->actingAs($this->admin)->post('/admin/convites', ['email' => 'prof@ufes.br'])->assertRedirect();
        $this->assertSame('Substituído', $primeiro->fresh()->situacao());

        $segundo = Convite::whereKeyNot($primeiro->id)->sole();
        $this->actingAs($this->admin)->patch("/admin/convites/{$segundo->id}/cancelar")->assertRedirect();
        $this->assertSame('Cancelado', $segundo->fresh()->situacao());

        $this->actingAs($this->admin)->get('/admin/convites')->assertSee('Substituído')->assertSee('Cancelado');
    }

    public function test_e8_pagina_de_acesso_negado_explica_o_que_pode_ter_acontecido(): void
    {
        $outroCoordenador = Usuario::factory()->coordenador()->create();

        $this->actingAs($outroCoordenador)->get("/subprojetos/{$this->subprojeto->id}/edit")
            ->assertForbidden()
            ->assertSee('Esta ação não está disponível')
            ->assertSee('Volte e recarregue a página');
    }

    public function test_ensaio_aviso_de_aceite_no_painel_tem_nome_e_link_da_entrega(): void
    {
        $estudante = $this->vinculado();
        $entrega = Entrega::factory()->comSituacao(SituacaoEntrega::AguardandoAceite)->create(['subprojeto_id' => $this->subprojeto->id, 'enviado_por' => $estudante->id]);

        $this->actingAs($this->solicitacao->demandante)->get('/painel')
            ->assertOk()
            ->assertSee("Entrega nº {$entrega->numero} — {$this->solicitacao->titulo}")
            ->assertSee(route('subprojetos.entregas.show', [$this->subprojeto, $entrega]), false);
    }

    public function test_ensaio_comentario_obrigatorio_tem_mensagem_na_tela(): void
    {
        $estudante = $this->vinculado();
        $entrega = Entrega::factory()->comSituacao(SituacaoEntrega::AguardandoAceite)->create(['subprojeto_id' => $this->subprojeto->id, 'enviado_por' => $estudante->id]);

        $this->actingAs($this->solicitacao->demandante)->get("/subprojetos/{$this->subprojeto->id}/entregas/{$entrega->id}")
            ->assertSee('Escreva um comentário para reportar a pendência');
    }

    public function test_ensaio_recusa_de_candidatura_com_motivo_opcional(): void
    {
        $estudante = $this->vinculado();
        $candidatura = Candidatura::create(['subprojeto_id' => $this->subprojeto->id, 'estudante_id' => $estudante->id]);

        $this->actingAs($this->coordenador)
            ->patch("/subprojetos/{$this->subprojeto->id}/candidaturas/{$candidatura->id}/recusar", ['motivo_recusa' => 'A equipe já tem alguém com esse perfil.'])
            ->assertRedirect();

        $this->assertSame('A equipe já tem alguém com esse perfil.', $candidatura->fresh()->motivo_recusa);
        Notification::assertSentTo($estudante, Aviso::class, fn (Aviso $aviso) => str_starts_with($aviso->assunto, 'Candidatura recusada')
            && in_array('Motivo: A equipe já tem alguém com esse perfil.', $aviso->linhas, true));
        $this->actingAs($estudante)->get('/subprojetos')->assertSee('Motivo da recusa: A equipe já tem alguém com esse perfil.');

        // Sem motivo continua valendo.
        $outra = Candidatura::create(['subprojeto_id' => $this->subprojeto->id, 'estudante_id' => $this->vinculado()->id]);
        $this->actingAs($this->coordenador)->patch("/subprojetos/{$this->subprojeto->id}/candidaturas/{$outra->id}/recusar")->assertRedirect();
        $this->assertNull($outra->fresh()->motivo_recusa);
    }
}
