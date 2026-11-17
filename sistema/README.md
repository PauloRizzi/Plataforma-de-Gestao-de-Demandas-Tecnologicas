# PGDT — aplicação Laravel

Código da **Plataforma de Gestão de Demandas Tecnológicas**. A visão geral do projeto está no [README da raiz](../README.md) e o passo a passo de instalação em [docs/INSTALACAO.md](../docs/INSTALACAO.md).

## Comandos úteis

```powershell
composer install          # dependências do PHP
npm install               # dependências das telas
npm run build             # gera CSS e JS (ou npm run dev durante o desenvolvimento)
php artisan migrate --seed
php artisan serve         # http://localhost:8000
php artisan queue:work    # envia os avisos por e-mail da fila
php artisan test          # testes automatizados
```
