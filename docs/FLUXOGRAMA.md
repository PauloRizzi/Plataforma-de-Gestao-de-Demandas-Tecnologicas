# Fluxogramas do sistema — PGDT

Plataforma de Gestão de Demandas Tecnológicas, do projeto de extensão "Rede de Apoio Tecnológico às Escolas" (UFES/CCENS — Alegre-ES).

Os diagramas estão em [Mermaid](https://mermaid.js.org/): o GitHub mostra os desenhos direto nesta página, e o [Mermaid Live Editor](https://mermaid.live) exporta cada um em PNG ou SVG para o texto do TCC.

Eles descrevem o sistema como está implementado (Fases 0 a 9, novembro de 2026).

**Perfis:** Administrador · Coordenador projeto de extensão · Demandante (comunidade/instituição) · Estudante Extensionista · Visitante (sem login)

**Termos:**
- **Projeto de extensão (Projeto Base):** a linha de extensão cadastrada pela coordenação (RF05).
- **Demanda (solicitação):** o pedido de uma comunidade ou instituição, sempre ligado a um projeto de extensão (RF06).
- **Sub-projeto:** a demanda priorizada que vira trabalho de uma equipe de estudantes (RF09).

---

## 1. Visão geral

O caminho de uma demanda, da abertura ao relatório de horas. Cada caixa indica quem faz a ação e o requisito.

```mermaid
flowchart TD
    subgraph P["Preparação"]
        A1["Admin aprova os cadastros<br/>e convida coordenadores<br/>(RF01–RF04)"]
        A2["Coordenador cadastra o projeto de extensão<br/>e vincula estudantes extensionistas<br/>(RF05)"]
        A1 --> A2
    end

    subgraph D["Demanda"]
        B1["Demandante abre a demanda com anexos<br/>(a coordenação também pode abrir)<br/>(RF06)"]
        B2["Coordenador faz a triagem:<br/>categoria, prioridade e complexidade<br/>(RF07)"]
        B3{"A demanda é viável?"}
        B4["Recusada, com motivo"]
        B1 --> B2 --> B3
        B3 -- Não --> B4
    end

    subgraph E["Execução"]
        C1["Coordenador cria o sub-projeto:<br/>escopo, datas, vagas, modalidade<br/>e carga horária (RF09)"]
        C2["Estudantes vinculados se candidatam<br/>pela vitrine ou pela página pública<br/>(RF10, RF14)"]
        C3["Coordenador aprova as candidaturas<br/>e monta a equipe com papéis<br/>(RF11)"]
        C4["Equipe envia as entregas<br/>(RF12)"]
        C1 --> C2 --> C3 --> C4
    end

    subgraph V["Validação em duas etapas"]
        D1{"Coordenador:<br/>aprovação técnica?<br/>(RF13, etapa 1)"}
        D2{"Demandante:<br/>aceita a solução?<br/>(RF13, etapa 2)"}
        D1 -- Sim --> D2
    end

    subgraph F["Encerramento"]
        E1["Coordenador muda a demanda<br/>para Concluída"]
        E2["Relatório de horas por extensionista,<br/>com exportação em PDF<br/>(RF15)"]
        E1 --> E2
    end

    A2 --> B1
    B3 -- Sim --> C1
    C4 --> D1
    D1 -- "Não: devolve para ajustes" --> C4
    D2 -- "Não: reporta pendência" --> C4
    D2 -- Sim --> E1

    R["Em todas as etapas: linha do tempo automática,<br/>chat da demanda e avisos por e-mail (RF08)"]
    D ~~~ R
```

---

## 2. Cadastro e acesso (RF01 a RF04)

Cada perfil entra por um caminho próprio, para ninguém escolher o perfil errado. O login é um só para todos.

```mermaid
flowchart TD
    V0(["Visitante"]) --> V1["Criar conta"]
    V1 --> V2{"Quem é você?"}

    V2 -- "Sou estudante da UFES" --> S1["Formulário do estudante<br/>(matrícula e curso)"]
    S1 --> S2{"E-mail @edu.ufes.br?"}
    S2 -- Não --> S3["Bloqueia e indica<br/>o cadastro de demandante"]
    S2 -- Sim --> S4["Confirma o e-mail<br/>pelo link recebido"]

    V2 -- "Represento uma comunidade<br/>ou instituição" --> M1["Formulário do demandante<br/>(comunidade/instituição, cargo e telefone)"]
    M1 --> M2{"E-mail da UFES?"}
    M2 -- Sim --> M3["Aviso: quer se cadastrar<br/>como estudante?"]
    M2 -- Não --> P0
    M3 -- "Continua como demandante" --> P0

    S4 --> P0["Conta Pendente:<br/>tela Cadastro em análise"]
    P0 --> AD{"Admin analisa o cadastro"}
    AD -- Aprova --> AT["Conta Ativa<br/>(e-mail de aprovação)"]
    AD -- "Rejeita, com motivo" --> RJ["Conta Rejeitada<br/>(e-mail com o motivo)"]

    CV["Admin envia convite por e-mail<br/>(link de uso único, 7 dias)"] --> CO["Coordenador define a senha"]
    CO --> AT

    AT --> LG["Login único"]
    LG --> PN["Painel e menu do perfil"]
    AT -. "Admin pode inativar<br/>ou trocar o perfil" .-> IN["Conta Inativa:<br/>não entra no sistema"]
```

---

## 3. Ciclo de status da demanda

Todas as mudanças são manuais e feitas pelo coordenador ou pelo admin. Ao criar o sub-projeto, enviar uma entrega ou registrar o aceite, a tela sugere o próximo status. Cada mudança vai para a linha do tempo, com quem mudou e quando.

```mermaid
stateDiagram-v2
    [*] --> Cadastrada: demanda aberta (RF06)
    Cadastrada --> EmAnalise
    EmAnalise --> Priorizada: depois da triagem (RF07)
    Priorizada --> EmAnalise: reavaliar
    Priorizada --> EmDesenvolvimento: com o sub-projeto criado (RF09)
    EmDesenvolvimento --> EmValidacao: com entrega enviada (RF12)
    EmValidacao --> AguardandoAjustes: devolução ou pendência (RF13)
    AguardandoAjustes --> EmDesenvolvimento
    AguardandoAjustes --> EmValidacao
    EmDesenvolvimento --> Concluida: com entrega aceita
    EmValidacao --> Concluida: com entrega aceita
    AguardandoAjustes --> Concluida: com entrega aceita
    Cadastrada --> Recusada: com motivo
    EmAnalise --> Recusada: com motivo
    Priorizada --> Recusada: com motivo
    Recusada --> EmAnalise: reabrir
    Concluida --> [*]

    EmAnalise: Em Análise
    EmDesenvolvimento: Em Desenvolvimento
    EmValidacao: Em Validação
    AguardandoAjustes: Aguardando Ajustes
    Concluida: Concluída
```

**Cancelada** (com motivo) fica fora do desenho para ele não ficar poluído:
- o demandante cancela a própria demanda enquanto ela está Cadastrada, Em Análise ou Priorizada;
- o admin cancela em qualquer status, menos Concluída e Recusada (uma demanda concluída cancelada tiraria as horas dos estudantes do relatório).

---

## 4. Equipe do sub-projeto (RF09, RF10, RF11 e RF14)

O estudante só entra na equipe de um sub-projeto se estiver vinculado ao projeto de extensão dele.

```mermaid
flowchart TD
    A["Coordenador vincula estudantes<br/>ao projeto de extensão (RF05)"] --> B["Coordenador cria o sub-projeto<br/>com vagas e a opção Receber candidaturas (RF09)"]

    B --> M["Coordenador inclui estudantes<br/>vinculados direto na equipe"]
    B --> V{"Há vagas livres e a opção<br/>Receber candidaturas está marcada?"}
    V -- Sim --> VT["Sub-projeto aparece na vitrine (RF10)<br/>e na página pública (RF14)"]
    V -- Não --> FX["Sub-projeto fica fora da vitrine"]

    VT --> L{"Visitante ou estudante?"}
    L -- Visitante --> LG["Quero participar:<br/>login e volta ao sub-projeto"]
    LG --> E
    L -- "Estudante logado" --> E{"Estudante ativo e vinculado<br/>ao projeto de extensão?"}
    E -- Não --> BD["Botão desabilitado,<br/>com o motivo"]
    E -- Sim --> C["Candidatura com um clique"]

    C --> AV{"Coordenador avalia (RF11)"}
    AV -- "Recusa, com motivo opcional" --> RC["Estudante é avisado por e-mail"]
    AV -- "Aprova e escolhe o papel" --> EQ["Membro da equipe"]
    M --> EQ

    EQ --> PP["Papéis: Membro Desenvolvedor,<br/>Responsável Técnico, Apoio de Campo e Outros"]
    EQ -. "equipe completa" .-> FX
```

---

## 5. Entrega e validação em duas etapas (RF12 e RF13)

Cada entrega é numerada e avaliada separadamente. A equipe pode enviar novas entregas enquanto a demanda está em execução.

```mermaid
flowchart TD
    A["Membro da equipe envia a entrega nº N<br/>(link, relatório técnico ou arquivos/fotos)"] --> B["Entrega aguarda a validação técnica"]
    B --> C{"Coordenador:<br/>Aprovar Tecnicamente?"}
    C -- "Devolver para Ajustes<br/>(comentário obrigatório)" --> R1["Entrega devolvida:<br/>a equipe corrige e envia de novo"]
    R1 --> A
    C -- Aprovar --> D["Demandante passa a ver a entrega<br/>e recebe o aviso por e-mail"]
    D --> E{"Demandante:<br/>Aceitar Solução?"}
    E -- "Reportar Pendência<br/>(comentário obrigatório)" --> R2["Entrega com pendência:<br/>volta para a equipe"]
    R2 --> A
    E -- Aceitar --> F["Entrega aceita"]
    F --> G["Coordenador muda a demanda<br/>para Concluída"]
    G --> H["Horas do sub-projeto entram<br/>no relatório de cada membro (RF15)"]
```

Se a comunidade/instituição não tem demandante no sistema, a coordenação registra o aceite ou a pendência em nome dela. A linha do tempo mostra essa informação.
