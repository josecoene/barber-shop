# Prints das telas

Prints das telas de cada perfil (admin, barbeiro e cliente) e das telas abertas do visitante, tirados em 15/11/2026 na homolog local (imagens de produção, com `APP_ENV=production`, em http://localhost:8080), na branch `feat/entrega-final`. No mesmo dia, depois das correções da rodada de acabamento (o menu, os cadastros no celular e os blocos curtos da Agenda), 22 deles foram refeitos: a seção [Refeitos na rodada de acabamento](#refeitos-na-rodada-de-acabamento) diz quais e por quê.

Cada tela tem dois prints: um com 1280 px de largura (computador) e um com 390 px (celular). Os nomes seguem `<perfil>-<tela>-<largura>.png`. São 44 prints: 20 do admin, 10 do barbeiro, 6 da cliente e 8 do visitante.

Ficaram sem print a tela "Redefinir senha" (`/reset-password/{token}`, que só abre pelo link do e-mail) e as páginas de erro 403, 419, 429, 500 e 503, que usam o mesmo layout da página de erro 404.

## Como foram tirados

- **Dados de demonstração:** os do `DemoSeeder` (3 barbeiros com login, 15 clientes, 4 deles com conta, e 30 dias de agenda com pagamentos) e o admin do `AdminUserSeeder`. Nomes, e-mails (`@demo.barberflow.test`, um domínio reservado para teste) e telefones (`(11) 90000-xxxx`) são fictícios.
- **Logins:** o admin da homolog, o barbeiro Carlos Mendes e a cliente Ana Paula Ribeiro (a que tem o prêmio de fidelidade). As senhas foram geradas na hora e não aparecem em nenhum print: todo campo de senha está vazio.
- **Página inteira:** cada print mostra a página toda, de cima a baixo, sem a barra de rolagem. O de 390 px usa o modo celular do navegador embutido do Orca. Esse navegador captura no máximo cerca de 1400 px de altura de uma vez, então as páginas longas saíram em faixas da altura da janela, juntadas depois.
- **Dia da agenda:** 16/11/2026, segunda-feira. O dia dos prints, 15/11, é domingo, e a barbearia não abre.
- **Nada foi salvo:** os Agendar estão preenchidos até o resumo e o modal de pagamento está aberto, mas nenhum formulário foi enviado.
- **Os dados seguem o relógio:** o `DemoSeeder` monta os 30 dias em volta do dia em que roda. Prints refeitos em outro dia mostram outras datas e outros números.
- **Minha conta do barbeiro:** os dois prints entraram depois, ainda em 15/11/2026, na revisão final, com a homolog subida de novo (o MySQL num volume novo, e o `DemoSeeder` e o `AdminUserSeeder` rodados no mesmo dia). Para o print de 390 px, a janela de celular ganhou a altura da página (915 px), e o print saiu de uma vez, sem faixas.

## Refeitos na rodada de acabamento

Em 15/11/2026, a rodada de acabamento corrigiu os três detalhes visuais que os primeiros prints mostravam (as tabelas dos cadastros no celular, o menu do admin em 1280 px e os blocos curtos da Agenda). As correções mudam as telas, então os prints delas foram refeitos.

**Como:** na mesma homolog, com as imagens de produção feitas de novo com o código da rodada, e com o MySQL no volume da revisão final, que guardava os dados do `DemoSeeder` e do `AdminUserSeeder` de 15/11/2026 (o volume foi apagado depois, no mesmo dia, com o OK do Leonam, `docs/RELATORIO-TECNICO.md`, seção 4.12). Os mesmos logins, o mesmo dia de agenda (16/11/2026), os mesmos registros abertos (o detalhe de Daniel Rocha às 16:00, o modal de pagamento de Ana Paula Ribeiro e os resumos dos três Agendar) e o mesmo jeito de tirar a página inteira. Nenhum formulário foi enviado.

**Refeitos (22):**

- **Os 18 de 1280 px com login, por causa do menu (e, nas Agendas, dos blocos curtos):** do admin, [Dashboard](admin-dashboard-1280.png), [Agenda](admin-agenda-1280.png), [Agenda com o detalhe](admin-agenda-detalhe-1280.png), [Agendar](admin-agendar-1280.png), [Pagamentos](admin-pagamentos-1280.png), [Pagamentos com o modal](admin-pagamentos-modal-1280.png), [Clientes](admin-clientes-1280.png), [Barbeiros](admin-barbeiros-1280.png), [Serviços](admin-servicos-1280.png) e [Minha conta](admin-minha-conta-1280.png); do barbeiro, [Agenda](barbeiro-agenda-1280.png), [Agendar](barbeiro-agendar-1280.png), [Pagamentos](barbeiro-pagamentos-1280.png), [Clientes](barbeiro-clientes-1280.png) e [Minha conta](barbeiro-minha-conta-1280.png); da cliente, [Meus horários](cliente-meus-horarios-1280.png), [Agendar](cliente-agendar-1280.png) e [Minha conta](cliente-minha-conta-1280.png).
- **Os 4 dos cadastros em 390 px, por causa da lista no celular:** [Clientes](admin-clientes-390.png), [Barbeiros](admin-barbeiros-390.png) e [Serviços](admin-servicos-390.png) do admin e [Clientes](barbeiro-clientes-390.png) do barbeiro.

**Tirados de novo e iguais aos de antes (22):** os outros 14 de 390 px com login (o Dashboard, as duas Agendas, a Agenda com o detalhe, os três Agendar, os dois Pagamentos, o modal de pagamento, as três Minha conta e Meus horários) e os 8 do visitante. Cada um foi comparado pixel a pixel com o print de antes, e todos saíram iguais. Ficaram os arquivos de antes.

**O que mudou nas telas:**

- **Menu, em 1280 px:** fica numa linha só para os três perfis. No admin, o nome "Administrador da homolog" sai cortado com reticências ("Administrador da ho..."), e o Sair fica na mesma linha dos links; antes, ele descia para uma segunda linha. O nome inteiro continua no `title` e no texto do leitor de tela. No barbeiro e na cliente, o menu já cabia numa linha: só o espaço entre o nome e o perfil mudou, quase invisível. O menu abre a partir de 1200 px; abaixo disso, ele fica no botão "Abrir menu", como no celular.
- **Cadastros em 390 px:** Clientes, Barbeiros e Serviços saem numa lista no lugar da tabela, como Pagamentos: o nome em cima, cada dado com o nome da coluna ao lado e os botões no fim. O nome e o e-mail não quebram mais no meio da palavra, e a página ficou mais curta (o print de Clientes do admin caiu de 3470 px para 2558 px de altura). Na tabela, de 768 px para cima, o e-mail só quebra depois do @ e antes de cada ponto; em 1280 px, ele já cabia numa linha, e nada mudou à vista.
- **Agenda, em 1280 px:** cada bloco mostra só as linhas que cabem inteiras. Os de 15 minutos têm uma linha, com o início junto do cliente ("10:00 · Bruno Carvalho" e "12:00 · Isabela Castro"). O de 20 minutos tem duas ("17:30 · Gabriela Lima" e "Barba"), e a do serviço não aparece mais cortada pela metade. Os de 30 e 45 minutos continuam com as 4 linhas. No celular, a Agenda continua a mesma lista por barbeiro.
- **Pagamentos:** a mudança da rodada (o texto escondido para o leitor de tela preso à área da tabela, para uma tabela larga não fazer a página inteira rolar) não aparece nos prints: os de 390 px saíram iguais, e os de 1280 px mudaram só no menu.

Fora o menu, os blocos curtos da Agenda e a lista dos cadastros, os prints refeitos têm o mesmo conteúdo dos de antes: a comparação pixel a pixel achou só diferenças de suavização (bordas arredondadas e letras deslocadas menos de 1 px).

**Conferência:** os 22 prints novos foram olhados um a um antes de entrar nesta pasta. Nenhum mostra senha, token ou e-mail de verdade, nenhum tem rolagem lateral (em todos, a largura da página é a da janela), nenhuma linha de texto aparece cortada pela metade (o nome do admin no menu é cortado com reticências, de propósito) e, em todos os de 1280 px, o menu ocupa uma linha só, com o Sair nela.

## Admin

| Tela | Endereço | 1280 px | 390 px | O que mostra |
|---|---|---|---|---|
| Dashboard | `/dashboard` | [admin-dashboard-1280.png](admin-dashboard-1280.png) | [admin-dashboard-390.png](admin-dashboard-390.png) | Mês atual (1 a 30/11/2026): faturamento, atendimentos, ticket médio, taxa de faltas e ocupação. Embaixo, o faturamento por dia e por forma de pagamento, os atendimentos por status, os 5 serviços mais feitos e os números de cada barbeiro |
| Agenda | `/appointments?date=2026-11-16` | [admin-agenda-1280.png](admin-agenda-1280.png) | [admin-agenda-390.png](admin-agenda-390.png) | Uma coluna por barbeiro no computador e uma lista por barbeiro no celular, com a cor de cada status. No computador, os blocos de 15 e 20 minutos mostram só as linhas que cabem, com o início junto do cliente, e o cancelado fica à parte, em "Cancelados e faltas"; no celular, ele fica na lista do barbeiro, com o selo Cancelado |
| Agenda, detalhe aberto | `/appointments?date=2026-11-16` | [admin-agenda-detalhe-1280.png](admin-agenda-detalhe-1280.png) | [admin-agenda-detalhe-390.png](admin-agenda-detalhe-390.png) | Detalhe do agendamento de Daniel Rocha às 16:00: dados, lembrete ainda não enviado e as ações Confirmar, Cancelar, Enviar lembrete agora, Remarcar e Fechar. Iniciar e Faltou só aparecem a partir do horário |
| Agendar | `/appointments/create?date=2026-11-16` | [admin-agendar-1280.png](admin-agendar-1280.png) | [admin-agendar-390.png](admin-agendar-390.png) | Novo agendamento com cliente, barbeiro, serviço, data, os horários livres do barbeiro e o resumo (Bruno Carvalho com Diego Almeida, Corte de cabelo, 14:00) |
| Pagamentos | `/payments` | [admin-pagamentos-1280.png](admin-pagamentos-1280.png) | [admin-pagamentos-390.png](admin-pagamentos-390.png) | Atendimentos sem pagamento, filtros, totais por status e a primeira página dos 95 pagamentos (tabela no computador, lista no celular), com Corrigir, Estornar e Marcar como pago |
| Pagamentos, modal aberto | `/payments` | [admin-pagamentos-modal-1280.png](admin-pagamentos-modal-1280.png) | [admin-pagamentos-modal-390.png](admin-pagamentos-modal-390.png) | Registrar pagamento do atendimento de Ana Paula Ribeiro, com o aviso do prêmio de fidelidade e a caixa "Usar o prêmio de fidelidade" |
| Clientes | `/clients` | [admin-clientes-1280.png](admin-clientes-1280.png) | [admin-clientes-390.png](admin-clientes-390.png) | Os 15 clientes, com Editar e Excluir e a Fidelidade: as visitas desde o último prêmio (como 6/10) e o selo "Prêmio disponível". Tabela no computador e lista no celular |
| Barbeiros | `/barbers` | [admin-barbeiros-1280.png](admin-barbeiros-1280.png) | [admin-barbeiros-390.png](admin-barbeiros-390.png) | Os 3 barbeiros, com o Acesso (se têm login). Tabela no computador e lista no celular |
| Serviços | `/services` | [admin-servicos-1280.png](admin-servicos-1280.png) | [admin-servicos-390.png](admin-servicos-390.png) | Os 4 serviços, com duração, preço e status. Tabela no computador e lista no celular |
| Minha conta | `/account` | [admin-minha-conta-1280.png](admin-minha-conta-1280.png) | [admin-minha-conta-390.png](admin-minha-conta-390.png) | Dados do admin e o formulário de troca de senha, vazio |

## Barbeiro (Carlos Mendes)

| Tela | Endereço | 1280 px | 390 px | O que mostra |
|---|---|---|---|---|
| Agenda | `/appointments?date=2026-11-16` | [barbeiro-agenda-1280.png](barbeiro-agenda-1280.png) | [barbeiro-agenda-390.png](barbeiro-agenda-390.png) | Só a coluna dele. O menu tem Agenda, Agendar, Pagamentos e Clientes |
| Agendar | `/appointments/create?date=2026-11-16` | [barbeiro-agendar-1280.png](barbeiro-agendar-1280.png) | [barbeiro-agendar-390.png](barbeiro-agendar-390.png) | O campo Barbeiro vem fixo com ele ("Você agenda na sua própria agenda"). Preenchido até o resumo (Marcelo Pires, Corte + Barba, 11:00) |
| Pagamentos | `/payments` | [barbeiro-pagamentos-1280.png](barbeiro-pagamentos-1280.png) | [barbeiro-pagamentos-390.png](barbeiro-pagamentos-390.png) | Só os 34 pagamentos da agenda dele, sem o filtro de barbeiro. Sem Corrigir e Estornar: ele só marca como pago |
| Clientes | `/clients` | [barbeiro-clientes-1280.png](barbeiro-clientes-1280.png) | [barbeiro-clientes-390.png](barbeiro-clientes-390.png) | Os mesmos clientes do admin, com Editar e sem Excluir. Tabela no computador e lista no celular |
| Minha conta | `/account` | [barbeiro-minha-conta-1280.png](barbeiro-minha-conta-1280.png) | [barbeiro-minha-conta-390.png](barbeiro-minha-conta-390.png) | Dados do barbeiro (nome, o e-mail do login, o perfil Barbeiro e o e-mail confirmado) e a troca de senha, vazia. Como no admin, Minha conta fica à direita do menu, ao lado do nome |

## Cliente (Ana Paula Ribeiro)

| Tela | Endereço | 1280 px | 390 px | O que mostra |
|---|---|---|---|---|
| Meus horários | `/my-appointments` | [cliente-meus-horarios-1280.png](cliente-meus-horarios-1280.png) | [cliente-meus-horarios-390.png](cliente-meus-horarios-390.png) | Fidelidade com 1 prêmio disponível e a barra de 1 de 10 visitas para o próximo. Os próximos horários, com Cancelar (até 2 horas antes), e os passados, com o status do pagamento |
| Agendar | `/booking?date=2026-11-16` | [cliente-agendar-1280.png](cliente-agendar-1280.png) | [cliente-agendar-390.png](cliente-agendar-390.png) | A cliente agenda para si, sem o campo Cliente. Preenchido até o resumo (Rafael Souza, Corte de cabelo, 10:00) |
| Minha conta | `/account` | [cliente-minha-conta-1280.png](cliente-minha-conta-1280.png) | [cliente-minha-conta-390.png](cliente-minha-conta-390.png) | Dados da conta, com o e-mail confirmado, e a troca de senha, vazia. Para a cliente, Minha conta fica no menu |

## Visitante (sem login)

| Tela | Endereço | 1280 px | 390 px | O que mostra |
|---|---|---|---|---|
| Login | `/login` | [visitante-login-1280.png](visitante-login-1280.png) | [visitante-login-390.png](visitante-login-390.png) | Formulário vazio, com os links Esqueci minha senha e Criar conta |
| Criar conta | `/register` | [visitante-criar-conta-1280.png](visitante-criar-conta-1280.png) | [visitante-criar-conta-390.png](visitante-criar-conta-390.png) | Cadastro de cliente (nome, e-mail, telefone opcional e senha), vazio |
| Esqueci minha senha | `/forgot-password` | [visitante-esqueci-senha-1280.png](visitante-esqueci-senha-1280.png) | [visitante-esqueci-senha-390.png](visitante-esqueci-senha-390.png) | Pedido do link para criar uma senha nova, vazio |
| Erro 404 | `/pagina-que-nao-existe` | [visitante-erro-404-1280.png](visitante-erro-404-1280.png) | [visitante-erro-404-390.png](visitante-erro-404-390.png) | Página de erro do BarberFlow para um endereço que não existe (o servidor responde 404) |
