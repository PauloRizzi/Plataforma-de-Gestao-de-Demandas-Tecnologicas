# Plataforma de Gestão de Demandas Tecnológicas (PGDT)

Sistema web desenvolvido como Trabalho de Conclusão de Curso (TCC) em Sistemas de Informação, para organizar as demandas tecnológicas que escolas e instituições trazem para os projetos de extensão: do pedido do demandante até a entrega validada pela coordenação e aceita por quem pediu.

## Funcionalidades

- **Perfis:** administrador, coordenador, demandante e estudante, cada um com seu painel.
- **Demandas:** abertura com anexos, triagem e priorização pela coordenação, recusa com motivo e linha do tempo de cada etapa.
- **Sub-projetos e equipes:** criação de sub-projetos a partir das demandas, vitrine de vagas, candidaturas e formação das equipes.
- **Entregas:** envio pelos estudantes, validação pela coordenação e aceite pelo demandante.
- **Comunicação:** chat entre demandante e coordenação e avisos por e-mail.
- **Gestão:** cadastros pendentes, convites, Projetos Base, parâmetros do sistema, dashboard e relatórios em PDF (incluindo relatório de horas do estudante).

## Tecnologias

- PHP 8.3 + Laravel
- MySQL / MariaDB (XAMPP)
- Bootstrap, Sass e Vite
- PHPUnit (testes automatizados com SQLite em memória)

## Estrutura do repositório

| Pasta | Conteúdo |
|---|---|
| `sistema/` | Código do sistema (aplicação Laravel) |
| `docs/` | Requisitos, casos de uso, DER, fluxograma e guia de instalação |
| `PLANO_DE_FASES.md` | Planejamento do desenvolvimento por fases |
| `INICIAR-SISTEMA.bat` | Liga o sistema em http://localhost:8000 (Windows, depois de instalado) |

## Como rodar

O passo a passo completo para Windows está em [docs/INSTALACAO.md](docs/INSTALACAO.md). Resumo:

```powershell
cd sistema
composer install
npm install
npm run build
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Depois, abra http://localhost:8000.

## Documentação

- [Requisitos](docs/REQUISITOS.md)
- [Casos de uso](docs/CASOS_DE_USO.md)
- [Diagrama entidade-relacionamento](docs/DER.md)
- [Fluxograma](docs/FLUXOGRAMA.md)

## Autor

Paulo Henrique Rizzi — TCC de Sistemas de Informação.
