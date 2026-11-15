# Deploy do BarberFlow

Este arquivo só aponta para onde cada passo do deploy está escrito. O passo a passo fica num lugar só, para não existirem duas versões do mesmo roteiro que deixam de bater na primeira mudança (um rollback sem a conferência do compose, por exemplo).

| Quero | Onde está |
|---|---|
| Subir a AWS pela primeira vez (capacidade em 0, `APP_KEY`, imagens e a capacidade de verdade) | `docs/nuvem/TERRAFORM.md`, seção "Primeiro deploy" (e, com o GitHub, `docs/nuvem/CI-CD.md`, "Configurar o GitHub", passo 5, "Primeiro deploy") |
| Publicar uma versão nova (tag `v*`, aprovação, build, Trivy, ECR, instance refresh e `/api/health`) | `docs/nuvem/CI-CD.md`, seção "O deploy" |
| Voltar para a versão anterior | `docs/nuvem/CI-CD.md`, seção "Rollback" |
| Saber o que acontece com o banco num deploy e num rollback | `docs/nuvem/CI-CD.md`, seção "Migrations: só para frente" |
| Mudar o `docker-compose.prod.yml` ou o `user_data` | `docs/nuvem/CI-CD.md`, seção "Ordem com o terraform apply" |
| Voltar o banco para um minuto do passado | `docs/nuvem/BACKUP.md` |
| Ver os alarmes e o dashboard depois do deploy | `docs/nuvem/TERRAFORM.md`, seção "Observabilidade" |
| Conferir tudo antes de abrir para os clientes | `docs/nuvem/go-live-checklist.md` |

**Situação:** validado só no código. Nada foi para a AWS nem para o GitHub (`docs/nuvem/CI-CD.md`, "Situação conferida em 14/11/2026").
