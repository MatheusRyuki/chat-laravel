# Roteiro de validação manual

Este roteiro usa contas fictícias e um banco SQLite separado em **http://127.0.0.1:8002**. A aplicação de desenvolvimento em http://localhost:8000 usa o MySQL `chat`; mantenha os testes deste roteiro na porta 8002 para preservar esses dados.

Cada caso começa como **Não executado**. Preencha o status e as observações conforme fizer o teste.

---

## Como usar este ambiente

Antes de começar, siga a [instalação do projeto](../README.md). Os scripts deste roteiro devem ser executados em Linux ou WSL2, com PHP 8.5, SQLite, GD, curl e o comando `ss` disponíveis no ambiente local. Veja também os [requisitos dos testes](testes.md#preparar-o-ambiente-local).

Configure as credenciais do Pusher no `.env` e gere os arquivos do frontend para conferir as atualizações em tempo real. Em seguida, [inicie o ambiente isolado](#subir-o-ambiente-isolado) antes de acessar as contas abaixo.

### URL e credenciais fictícias

| Conta | Nome | E-mail | Senha | Papel |
| --- | --- | --- | --- | --- |
| A | Ana Manual | `ana.manual@example.com` | `password` | Principal |
| B | Bruno Manual | `bruno.manual@example.com` | `password` | Segunda janela; histórico paginado |
| C | Carla Manual | `carla.manual@example.com` | `password` | Conversa vazia; membro do grupo; alvo de bloqueio |
| D | Davi Manual | `davi.manual@example.com` | `password` | Fora do Grupo ABC (acesso negado) |
| E | Eva Manual | `eva.manual@example.com` | `password` | Perfil e inclusão no grupo |
| F | Descartavel Manual | `descartavel.manual@example.com` | `password` | Troca/recuperação de senha e exclusão (por último) |

Atalhos já preparados:

- Conversa vazia A–C: http://127.0.0.1:8002/?contato=3
- Histórico A–B (≥ dois lotes anteriores): http://127.0.0.1:8002/?contato=2
- Grupo ABC (A criadora, B e C membros): http://127.0.0.1:8002/?grupo=2
- Modal Novo grupo (sem JS): http://127.0.0.1:8002/?criar_grupo=1
- Anexo pré-carregado A→B: http://127.0.0.1:8002/mensagens/111/anexo

Imagens de teste em `tests/e2e/storage/manuais/`:

- `anexo-ok.png` e `anexo-ok.jpg` — 320×200, JPEG/PNG válidos
- `anexo-grande.png` — maior que 2 MB
- `anexo-invalido.gif` e `anexo-invalido.txt` — formatos recusados

### Primeiros passos

1. Abra **http://127.0.0.1:8002/login** (não :8000).
2. Entre com a conta **A**.
3. Abra um **navegador ou perfil diferente** e entre com a conta **B**.
4. Siga os casos na ordem. Ações que apagam dados ficam no final (M-34).

### Sessões independentes

- **Duas contas ao mesmo tempo:** use dois navegadores, ou janela normal + anônima/privada. Perfis diferentes não compartilham cookie `chat_e2e_session`.
- **Logout entre abas (M-33):** use duas abas do **mesmo** perfil. O sinal vai por `localStorage` (`chat-sessao-encerrada`).
- **Rascunho:** fica em `sessionStorage` **por aba**. Não aparece em outro navegador.
- Não faça login/logout em http://localhost:8000 durante este teste.

### Subir o ambiente isolado

Na raiz do repositório:

```bash
tests/e2e/iniciar-manual.sh
```

O script sobe `http://127.0.0.1:8002` com SQLite próprio, cookie `chat_e2e_session`, anexos em `tests/e2e/storage/anexos` e prefixo Pusher `e2e-`. Não usa o MySQL `chat` nem a porta 8000.

Se o servidor já estiver no ar, o script informa e preserva os dados. Para recomeçar do zero:

```bash
tests/e2e/parar-manual.sh
tests/e2e/iniciar-manual.sh --reset
```

Não use `tests/e2e/iniciar.sh` (ele apaga o SQLite e semeia as contas do Playwright). Não rode PHPUnit nem Playwright ao mesmo tempo que este roteiro.

### Encerrar só o isolado

```bash
tests/e2e/parar-manual.sh
```

O comando encerra o servidor manual da porta 8002. Os containers da aplicação e do MySQL continuam em execução.

Link de recuperação de senha (caso M-07): o correio isolado vai para o log em `tests/e2e/storage/manual-serve.log`. Extraia a URL:

```bash
grep -oE 'http://127.0.0.1:8002/reset-password/[^"? ]+' tests/e2e/storage/manual-serve.log | tail -1
```

---

## Inventário da aplicação

Base: **http://127.0.0.1:8002**. Visitante autenticado é redirecionado de `/login` e `/register` para `/`. Visitante anônimo em rota `auth` vai para `/login`.

### Páginas navegáveis (GET)

| URL | Quem acessa | Tela |
| --- | --- | --- |
| `/login` | visitante | Login (Breeze) |
| `/register` | visitante | Cadastro |
| `/forgot-password` | visitante | Pedido de link de redefinição |
| `/reset-password/{token}` | visitante | Nova senha (token do e-mail/log) |
| `/` | autenticado | Chat; estado inicial “Selecione um contato” |
| `/?contato={id}` | autenticado | Conversa 1:1. 404 se o id for o próprio usuário ou inexistente |
| `/?grupo={id}` | autenticado | Grupo. 404 se o grupo não existir ou o usuário não for membro ativo |
| `/?criar_grupo=1` | autenticado | Mesma página do chat com o `<dialog>` Novo grupo aberto (fluxo sem JS) |
| `/?grupo={id}&gerenciar_membros=1` | autenticado, criador | Chat com o `<dialog>` Participantes do grupo aberto (fluxo sem JS) |
| `/profile` | autenticado | Conta: dados, senha, bloqueados, exclusão |
| `/mensagens/{id}/confirmacao-remocao` | autenticado, autor da mensagem | Confirmação HTML de remoção (alternativa sem JS) |
| `/verify-email` | autenticado | Prompt Breeze. **Não há link na UI do chat.** Usuário com `email_verified_at` é redirecionado para `/`. Sem verificação obrigatória no cadastro |
| `/confirm-password` | autenticado | Confirmação Breeze. **Não há link na UI** e nenhuma rota usa o middleware `password.confirm` |
| `/up` | público | Health check do Laravel (não é tela de produto) |

Rotas **só deste ambiente isolado** (`CHAT_E2E=1`): `/e2e/diagnostico` (JSON) e `/e2e/atrasar`. Não fazem parte do produto.

### Views existentes sem rota (não são páginas)

- `resources/views/welcome.blade.php`
- `resources/views/dashboard.blade.php` (o nome de rota `dashboard` aponta para o chat em `/`)

### Modais e diálogos na UI

- **Novo grupo:** `<dialog id="modal-criar-grupo">`. Com JS, o clique em “Novo grupo” abre o diálogo sem recarregar. Sem JS, o href `?criar_grupo=1` deixa o diálogo com `open`. Fechar: links que removem o parâmetro.
- **Gestão de membros:** botão **Gerenciar membros** no cabeçalho do grupo, só para a criadora. Abre o `<dialog id="modal-membros-grupo">`. Com JS, o clique não recarrega. Sem JS, `/?grupo={id}&gerenciar_membros=1` deixa o diálogo com `open`. Fechar: links que voltam a `/?grupo={id}`.
- **Excluir conta:** modal Alpine (`confirm-user-deletion`) em `/profile`. Sem JavaScript o botão “Delete Account” não abre o formulário.
- **Remover mensagem (com JS):** `window.confirm('Esta mensagem será removida para os participantes da conversa.')`.
- **Editar mensagem (com JS):** `window.prompt('Editar mensagem', …)`. Sem JS o botão `type="button"` não envia nada.
- **Gaveta (≤735 px):** lista de conversas; botão com ícone de barras; backdrop; Escape fecha.

### Endpoints acionados por formulário ou JavaScript (não são páginas)

| Método | URL | Uso |
| --- | --- | --- |
| POST | `/login`, `/register`, `/logout` | Sessão |
| POST | `/forgot-password`, `/reset-password` | Recuperação |
| PUT | `/password` | Troca de senha no perfil |
| PATCH | `/profile` | Nome e e-mail |
| DELETE | `/profile` | Exclusão da conta |
| POST | `/email/verification-notification` | Reenvio (Breeze; não usado no fluxo do chat) |
| POST | `/confirm-password` | Breeze; sem ligação |
| GET | `/mensagens` | JSON do histórico (`antes_id`, `depois_id`, `versao`) — botão “Carregar mensagens anteriores” e reconciliação |
| POST | `/mensagens` | Enviar texto/anexo (form multipart ou `fetch`) |
| PATCH | `/mensagens/{id}` | Editar |
| DELETE | `/mensagens/{id}` | Remover (marca removida; apaga o arquivo do anexo) |
| GET | `/mensagens/{id}/anexo` | Stream do arquivo (auth + Gate `verAnexo`) |
| POST | `/leituras` | Marcar lidas |
| POST | `/digitacao` | Sinal de digitação |
| POST | `/grupos` | Criar grupo |
| POST | `/grupos/{conversa}/membros` | Adicionar membro (só criador) |
| DELETE | `/grupos/{conversa}/membros/{user}` | Remover membro (só criador; não remove a si) |
| POST | `/bloqueios` | Bloquear |
| DELETE | `/bloqueios/{id}` | Desbloquear (só quem criou o bloqueio) |
| POST | `/broadcasting/auth` | Autorização Pusher (Echo) |

### Comportamentos que o roteiro cobre (implementados)

- Mensagem: máximo **1000** caracteres (`maxlength` + validação). Vazio recusado. `Shift+Enter` quebra linha. `Enter` envia no desktop; no celular (viewport ≤735 px ou ponteiro grosso) o Enter **não** envia.
- Links `http://` e `https://` viram âncora com `target="_blank"` e `rel="noopener noreferrer"`.
- Rascunho: `sessionStorage` chave `chat-rascunho:{userId}:{contatoId}` ou `…:grupo:{grupoId}`.
- Histórico: janela de **50**. Com 111 mensagens A–B, o primeiro “Carregar mensagens anteriores” busca o lote 12–61 e o segundo o 1–11. Prepend preserva a posição de rolagem.
- Anexo: só conversa **individual**; JPEG/PNG; máximo **2 MB**. Grupo recusa anexo.
- Bloqueio 1:1: interrompe envio, edição, remoção e digitação nos dois sentidos; histórico permanece; grupos compartilhados **não** são ocultados.
- Grupo: nome obrigatório, máx. 80; pelo menos um participante. Removido perde a lista/eventos; `/?grupo=` passa a 404. Com a conversa aberta, o JS mostra “Você não faz mais parte deste grupo.”
- Presença: Online / Offline / Presença indisponível. Digitação: “{nome} está digitando…”.
- Queda Pusher: `window.__chatDesconectarPusher()` e `window.__chatReconectarPusher()` no console; ao reconectar, `reconciliar('reconexao')`.
- Logout entre abas: `localStorage chat-sessao-encerrada`.
- Canais Pusher deste isolado: prefixo **`e2e-`** (não misturam com :8000).

---

## 1. Cadastro, login, erros e visitante

### M-01 — Acesso como visitante

- **ID:** M-01
- **Objetivo:** Confirmar redirecionamento das telas autenticadas e disponibilidade das telas guest.
- **Conta:** nenhuma (janela anônima).
- **Preparação:** não estar autenticado em :8002.
- **URL inicial:** http://127.0.0.1:8002/
- **Passos:**
  1. Abra `/`.
  2. Abra `/profile`.
  3. Abra `/mensagens/111/anexo`.
  4. Abra `/login`, `/register` e `/forgot-password`.
- **Resultado esperado:** `/`, `/profile` e o anexo respondem 302 para `/login`. As três telas guest renderizam o formulário Breeze (Email, Password, Register, “Forgot your password?”).
- **Status:** Não executado
- **Observações / captura:**


### M-02 — Erros de login

- **ID:** M-02
- **Objetivo:** Validação, credencial inválida e limite de tentativas.
- **Conta:** nenhuma (não use A/B para não travar a sessão principal).
- **Preparação:** URL http://127.0.0.1:8002/login
- **Passos:**
  1. Clique em Log in sem preencher (HTML `required` pode bloquear o envio).
  2. Envie e-mail `naoexiste.manual@example.com` e senha `errada` (cinco vezes). Observe a mensagem.
  3. Envie a sexta tentativa com o mesmo e-mail.
- **Resultado esperado:** credencial inválida mostra “Estas credenciais não coincidem com nossos registros.” Após 5 falhas, “Muitas tentativas de login. Tente novamente em :seconds segundos.” (o cache `array` com vários workers do `artisan serve` pode tornar o throttle irregular — registre o que ocorrer).
- **Status:** Não executado
- **Observações / captura:**


### M-03 — Cadastro com validação e sucesso

- **ID:** M-03
- **Objetivo:** Recusar dados inválidos e criar uma conta nova, que entra no chat.
- **Conta:** nova, descartável de cadastro (não é a F).
- **Preparação:** http://127.0.0.1:8002/register
- **Passos:**
  1. Envie o formulário vazio ou senha curta (`abc`) e senhas diferentes.
  2. Cadastre Nome `Cadastro Manual`, e-mail `cadastro.manual@example.com`, senha `password` / `password`.
  3. Observe se cai no chat sem pedir verificação de e-mail.
  4. Clique em **Sair**.
- **Resultado esperado:** validação de senha (mínimo 8, confirmação). Sucesso redireciona para `/` já autenticado. A lista passa a incluir os usuários seed. **Não há tela obrigatória de verify-email** (`MustVerifyEmail` está comentado no model).
- **Status:** Não executado
- **Observações / captura:**


### M-04 — Login com sucesso (conta A)

- **ID:** M-04
- **Objetivo:** Entrar com A e ver o estado inicial do chat.
- **Conta:** A
- **Preparação:** http://127.0.0.1:8002/login
- **Passos:**
  1. E-mail `ana.manual@example.com`, senha `password`, Log in.
  2. Observe a lista à esquerda e a área central.
- **Resultado esperado:** redireciona para `/`. Cabeçalho “Selecione um contato”. Composer desabilitado com “Selecione um contato à esquerda para escrever.” Lista com B, C, D, E, F, Grupo ABC e o cadastro de M-03 (se executado). Rodapé: Novo grupo, Conta, Sair.
- **Status:** Não executado
- **Observações / captura:**


---

## 2. Perfil, senha e recuperação

Use **E** e **F**, não A/B/C.

### M-05 — Perfil: ver, validar e atualizar

- **ID:** M-05
- **Objetivo:** Abrir a conta, recusar e-mail duplicado e alterar o nome com sucesso, depois reverter.
- **Conta:** E
- **Preparação:** login E. Botão **Conta** na sidebar ou http://127.0.0.1:8002/profile
- **Passos:**
  1. Confira as seções Profile Information, Update Password, Contatos bloqueados (“Nenhum contato bloqueado.”), Delete Account.
  2. Tente salvar o e-mail `ana.manual@example.com` (já usado).
  3. Altere o nome para `Eva Temporaria` e Save. Deve aparecer “Saved.” (some em ~2 s, Alpine).
  4. Volte ao chat e confira o nome no perfil da sidebar.
  5. **Reverta** o nome para `Eva Manual` e salve.
- **Resultado esperado:** e-mail duplicado falha na validação unique. Nome novo aparece no chat. Após reverter, a lista dos outros continua mostrando “Eva Manual”.
- **Status:** Não executado
- **Observações / captura:**


### M-06 — Troca de senha (conta F)

- **ID:** M-06
- **Objetivo:** Recusar senha atual errada e trocar com sucesso.
- **Conta:** F
- **Preparação:** login F → Conta → Update Password. http://127.0.0.1:8002/profile
- **Passos:**
  1. Current `errada`, New `password1`, Confirm `password1` → Save.
  2. Current `password`, New `password1`, Confirm `password1` → Save.
  3. Sair e entrar com `password1`.
- **Resultado esperado:** passo 1 falha (`current_password`). Passo 2 mostra “Saved.” Login antigo falha; o novo funciona. Anote a senha atual de F (`password1`) até M-07.
- **Status:** Não executado
- **Observações / captura:**


### M-07 — Recuperação de acesso (conta F)

- **ID:** M-07
- **Objetivo:** Pedir link, definir senha nova (voltar para `password`) e entrar.
- **Conta:** F (deslogada)
- **Preparação:** http://127.0.0.1:8002/forgot-password
- **Passos:**
  1. Informe `descartavel.manual@example.com` e envie.
  2. Confira a mensagem de status na página (texto Breeze em inglês se não houver tradução: “We have emailed your password reset link.”).
  3. Extraia o link do log (`tests/e2e/storage/manual-serve.log`) como na seção “Como usar”.
  4. Abra o link, defina senha `password` / `password`.
  5. Entre com F e `password`.
- **Resultado esperado:** POST em `/forgot-password` e GET `/reset-password/{token}`. Após o reset, redireciona ao login com status. F volta a usar `password` (necessário para M-34). E-mail inexistente deve falhar com a mensagem do broker.
- **Status:** Não executado
- **Observações / captura:**


### M-08 — Rotas Breeze sem ligação na UI

- **ID:** M-08
- **Objetivo:** Documentar o que existe se a URL for aberta direto, sem tratar como fluxo de produto.
- **Conta:** A (autenticada)
- **Preparação:** —
- **Passos:**
  1. Abra http://127.0.0.1:8002/verify-email (A tem e-mail verificado no seed).
  2. Abra http://127.0.0.1:8002/confirm-password. Envie senha errada e depois `password`.
- **Resultado esperado:** `/verify-email` redireciona A para `/`. `/confirm-password` renderiza o formulário; senha errada usa `auth.password`; senha certa redireciona ao chat. **Não há atalho no chat nem no perfil.**
- **Status:** Não executado
- **Observações / captura:**


---

## 3. Contatos, conversa vazia, mensagens e rascunhos

### M-09 — Lista e seleção

- **ID:** M-09
- **Objetivo:** Navegar a lista e o estado sem conversa aberta.
- **Conta:** A
- **Preparação:** http://127.0.0.1:8002/
- **Passos:**
  1. Sem selecionar ninguém, tente usar o composer.
  2. Abra Bruno, volte com o logo/lista, abra Carla, abra Grupo ABC.
  3. Tente `/?contato=1` (id da própria A) e `/?contato=999`.
- **Resultado esperado:** composer desabilitado até haver contato/grupo. URLs reais: `/?contato=2`, `/?contato=3`, `/?grupo=2`. Auto-contato e id inexistente: **404**.
- **Status:** Não executado
- **Observações / captura:**


### M-10 — Conversa vazia A–C

- **ID:** M-10
- **Objetivo:** Estado vazio antes da primeira mensagem.
- **Conta:** A (e C em outra janela, só para observar a lista)
- **Preparação:** http://127.0.0.1:8002/?contato=3
- **Passos:**
  1. Confira o cabeçalho (nome e e-mail de Carla) e a área de mensagens.
  2. Confira que o composer está habilitado (não há bloqueio).
  3. Na janela de C, a prévia de Ana deve ser “Nenhuma mensagem ainda” (ou equivalente da lista).
- **Resultado esperado:** texto “Nenhuma mensagem nesta conversa.” Não há “Carregar mensagens anteriores”. Não há clipe de anexo desabilitado — o clipe **aparece** (1:1).
- **Status:** Não executado
- **Observações / captura:**


### M-11 — Envio, limites, quebras, links e rascunho

- **ID:** M-11
- **Objetivo:** Exercitar o composer 1:1.
- **Conta:** A; observar C
- **Preparação:** A em `/?contato=3`. C logada em outra sessão, conversa com A ou lista.
- **Passos:**
  1. Envie o composer vazio (botão Enviar).
  2. Cole 1001 caracteres (o `maxlength=1000` deve cortar no campo). Envie 1000.
  3. Escreva duas linhas com `Shift+Enter` e envie.
  4. Envie `veja https://example.com agora`.
  5. Digite um rascunho “não enviar ainda”, clique em Bruno na lista, volte para Carla.
  6. No desktop, `Enter` deve enviar; não use isso no rascunho que quiser preservar.
- **Resultado esperado:** vazio → “A mensagem não pode estar vazia.” (ou o campo nem envia). 1000 caracteres aceitos. Quebra vira `<br>`. O link abre em nova aba com `noopener noreferrer`. Ao voltar de Bruno, o rascunho de Carla reaparece **nesta aba**. Na sessão de C a mensagem chega (se C estiver na conversa) ou a lista sobe com prévia/não lidas.
- **Status:** Não executado
- **Observações / captura:**


---

## 4. Tempo real, não lidas, presença e digitação

Duas sessões: **A** e **B**, perfis de navegador diferentes.

### M-12 — Recebimento em tempo real

- **ID:** M-12
- **Objetivo:** A mensagem aparece na outra conta sem recarregar.
- **Conta:** A e B
- **Preparação:** A e B em http://127.0.0.1:8002/?contato= (o outro).
- **Passos:**
  1. A envia `ping-tempo-real`.
  2. Observe a bolha em B sem F5.
  3. B responde `pong-tempo-real`.
- **Resultado esperado:** bolhas `replies` (próprias) e `sent` (recebidas), horário, lista da esquerda atualiza prévia e sobe o contato.
- **Status:** Não executado
- **Observações / captura:**


### M-13 — Atividade em outra conversa e não lidas

- **ID:** M-13
- **Objetivo:** Badge de não lidas quando a conversa não está aberta/visível.
- **Conta:** A e B
- **Preparação:** B abre a conversa com **C** (ou a lista em `/`). A permanece em `/?contato=2`.
- **Passos:**
  1. A envia `nao-lida-1` para B.
  2. Sem abrir A, B olha o item “Ana Manual” na lista.
  3. B abre a conversa com A.
- **Resultado esperado:** badge numérico no contato A. Ao abrir a conversa visível, o POST `/leituras` zera o badge.
- **Status:** Não executado
- **Observações / captura:**


### M-14 — Presença e digitação

- **ID:** M-14
- **Objetivo:** Online/offline e “está digitando”.
- **Conta:** A e B na conversa um com o outro
- **Preparação:** ambas as janelas visíveis
- **Passos:**
  1. Confira o ponto/texto de presença no cabeçalho e na lista (Online).
  2. A digita sem enviar; B deve ver “Ana Manual está digitando…”.
  3. A para ~3 s (config `digitacao_expira_em`).
  4. Feche a aba de B (não só minimize) e observe A.
- **Resultado esperado:** indicador some ao parar. Presença de B vai para Offline após a saída do canal de presença. Grupo não mostra ponto de presença individual (ícone de grupo).
- **Status:** Não executado
- **Observações / captura:**


---

## 5. Anexos

Arquivos em `tests/e2e/storage/manuais/`. Só 1:1 tem clipe.

### M-15 — Imagem sozinha

- **ID:** M-15
- **Objetivo:** Enviar PNG ou JPEG sem texto.
- **Conta:** A; observar B
- **Preparação:** A em `/?contato=2`. Anexe `anexo-ok.png` (ou `.jpg`).
- **Passos:**
  1. Clique no clipe e escolha o arquivo. Confira a pré-visualização.
  2. Envie com o campo de texto vazio.
  3. Em B, abra a imagem (nova aba → GET `/mensagens/{id}/anexo`).
- **Resultado esperado:** bolha só com `<img>`. Prévia na lista: “Imagem”. B vê o mesmo. `Content-Disposition: inline`.
- **Status:** Não executado
- **Observações / captura:**


### M-16 — Imagem com texto

- **ID:** M-16
- **Objetivo:** Legenda + arquivo.
- **Conta:** A e B
- **Preparação:** `/?contato=2`
- **Passos:**
  1. Texto `legenda da foto` + `anexo-ok.jpg`.
  2. Envie e confira os dois lados.
- **Resultado esperado:** texto e imagem na mesma bolha. Prévia “Imagem · legenda da foto”.
- **Status:** Não executado
- **Observações / captura:**


### M-17 — Validações de upload

- **ID:** M-17
- **Objetivo:** Recusar tipo, tamanho e anexo em grupo.
- **Conta:** A
- **Preparação:** 1:1 com B; depois Grupo ABC
- **Passos:**
  1. Em A–B, tente `anexo-invalido.gif` e `anexo-invalido.txt`. O `accept` do input pode barrar no seletor; nesse caso, anote. Se o arquivo passar, a API deve responder “Envie apenas imagens JPEG ou PNG.”
  2. Envie `anexo-grande.png` (>2 MB) → “A imagem deve ter no máximo 2 MB.”
  3. Abra `/?grupo=2` e confirme que **não há** input de arquivo. Um POST manual com anexo não faz parte deste roteiro de tela.
- **Resultado esperado:** grupo sem clipe. Mensagens de erro no `#erro-envio` ou `@error('anexo')`.
- **Status:** Não executado
- **Observações / captura:**


### M-18 — Proteção do anexo

- **ID:** M-18
- **Objetivo:** Visitante, não participante e mensagem removida não veem o arquivo.
- **Conta:** A (autora), D (estranho), visitante
- **Preparação:** URL do anexo da mensagem **111** (seed) ou da enviada em M-15 (anote o id no `href` da imagem).
- **Passos:**
  1. Visitante: abra `/mensagens/111/anexo` em janela anônima.
  2. Logado como D, abra a mesma URL.
  3. Como A, na conversa com B, **Remover** a mensagem de anexo **que você enviou agora** (não a 111, para preservar o seed até o fim se quiser). Confirme. Reabra o `href` do anexo removido.
- **Resultado esperado:** visitante → `/login`. D → **403**. Após remover: a bolha vira “Mensagem removida”; o GET do anexo → **403/404** (arquivo apagado + Gate `verAnexo` recusa removida).
- **Status:** Não executado
- **Observações / captura:**


---

## 6. Histórico, edição e remoção

### M-19 — Paginação, ordem e rolagem

- **ID:** M-19
- **Objetivo:** Dois lotes anteriores, ordem cronológica e preservação do scroll.
- **Conta:** A
- **Preparação:** recarregue http://127.0.0.1:8002/?contato=2 (janela de 50; seed tem 110 textos + 1 imagem no topo recente).
- **Passos:**
  1. Confirme que o botão “Carregar mensagens anteriores” está visível e que a conversa começa nas mensagens mais **novas** (incluindo a imagem do seed perto do fim). Role até o topo.
  2. Clique em Carregar. A primeira mensagem visível deve permanecer no mesmo lugar (ajuste de `scrollTop`). Devem surgir textos `Histórico A-B 12` … `61` (aprox.).
  3. Clique de novo. Devem surgir `Histórico A-B 1` … `11`. O botão some (`tem_anteriores` falso).
- **Resultado esperado:** ordem crescente de id/tempo de cima para baixo. Sem JS este botão (`type="button"`) não pagina — isso é M-32.
- **Status:** Não executado
- **Observações / captura:**


### M-20 — Edição com confirmar e cancelar

- **ID:** M-20
- **Objetivo:** `prompt` nativo: cancelar não muda; confirmar altera e marca “Editada”.
- **Conta:** A; observar B
- **Preparação:** A–B. Use uma mensagem **sua** recente (ex.: ping de M-12), não o histórico antigo se o botão não estiver visível após paginar.
- **Passos:**
  1. Clique em **Editar**. No prompt, cancele.
  2. Edite de novo, altere o texto e OK.
  3. Tente editar uma mensagem **do Bruno** (não deve haver botão).
- **Resultado esperado:** cancelar: inalterado. Confirmar: texto novo, selo “Editada”, PATCH `/mensagens/{id}`. B vê a alteração em tempo real. Sem JS o botão não faz nada (M-32).
- **Status:** Não executado
- **Observações / captura:**


### M-21 — Remoção com confirmar e cancelar

- **ID:** M-21
- **Objetivo:** `confirm` JS; a alternativa HTML permanece para M-32.
- **Conta:** A; observar B
- **Preparação:** envie `para-remover` e `para-manter`.
- **Passos:**
  1. Em `para-remover`, Remover → cancele o `confirm`.
  2. Remova de novo e confirme.
  3. Não apague o histórico seed inteiro.
- **Resultado esperado:** cancelar preserva. Confirmar: “Mensagem removida” nos dois lados; prévia “Mensagem removida” se era a última. Texto do confirm: “Esta mensagem será removida para os participantes da conversa.”
- **Status:** Não executado
- **Observações / captura:**


---

## 7. Grupos

Não remova B nem C do **Grupo ABC**. Adicione/remova **E**. Crie grupos novos para o teste de criação.

### M-22 — Validações ao criar grupo

- **ID:** M-22
- **Objetivo:** Nome e participantes obrigatórios.
- **Conta:** A
- **Preparação:** `/` → **Novo grupo** (ou `/?criar_grupo=1`)
- **Passos:**
  1. Abra o diálogo; o foco deve ir para “Nome do grupo”.
  2. Envie sem nome e sem checkboxes.
  3. Informe um nome de 81+ caracteres (o `maxlength=80` deve cortar).
- **Resultado esperado:** “Informe o nome do grupo.” / “Selecione pelo menos um participante.” O diálogo permanece aberto se a validação voltar com erros (`old` + `open`).
- **Status:** Não executado
- **Observações / captura:**


### M-23 — Criação com sucesso

- **ID:** M-23
- **Objetivo:** Grupo novo com pelo menos B.
- **Conta:** A; observar B
- **Preparação:** modal Novo grupo
- **Passos:**
  1. Nome `Grupo Teste Manual`, marque Bruno Manual, Criar grupo.
  2. Em B, veja a lista (evento `participante` / ao recarregar).
- **Resultado esperado:** redirect `/?grupo={id}` e aviso “Grupo criado. Novos membros podem consultar o histórico do grupo.” B passa a ver o grupo. Composer de grupo **sem** clipe.
- **Status:** Não executado
- **Observações / captura:**


### M-24 — Gestão de membros

- **ID:** M-24
- **Objetivo:** Só a criadora adiciona/remove; novos veem o histórico.
- **Conta:** A no Grupo ABC (`/?grupo=2`); E em outra sessão
- **Preparação:** A no Grupo ABC (`/?grupo=2`); E em outra sessão
- **Passos:**
  1. Como B no Grupo ABC, confirme que **não** há **Gerenciar membros** no cabeçalho.
  2. Como A, clique em **Gerenciar membros** no cabeçalho (não deve haver bloco abaixo do compositor).
  3. No modal, adicione Eva Manual.
  4. Como E, abra o grupo (deve aparecer na lista) e veja “Bem-vindos ao Grupo ABC”.
  5. Como A, abra de novo **Gerenciar membros** se o modal tiver fechado e remova Eva.
- **Resultado esperado:** aviso “Eva Manual entrou no grupo e pode consultar o histórico.” Após remover, E some da lista de participantes. Não remova A (a UI não oferece botão para si; DELETE a si → 422).
- **Status:** Não executado
- **Observações / captura:**


### M-25 — Acesso negado e após remoção

- **ID:** M-25
- **Objetivo:** 404 para quem não é membro; UI ao vivo para quem é removido com a conversa aberta.
- **Conta:** D; E (ainda removida de M-24); opcionalmente adicione E de novo, deixe a conversa aberta em E e remova
- **Preparação:** —
- **Passos:**
  1. D acessa http://127.0.0.1:8002/?grupo=2
  2. E acessa `/?grupo=2` após ter sido removida.
  3. (Opcional) A adiciona E, E deixa o grupo aberto, A remove E de novo: E deve ver “Você não faz mais parte deste grupo.” e o item some da lista.
- **Resultado esperado:** D e E (removida) recebem **404** ao abrir a URL. Quem está na tela no momento da remoção não precisa dar F5 para perder o composer.
- **Status:** Não executado
- **Observações / captura:**


---

## 8. Bloquear e desbloquear

Não bloqueie A–B até terminar tempo real. Alvo: **C**. Desbloqueie no fim deste bloco.

### M-26 — Bloquear C

- **ID:** M-26
- **Objetivo:** A bloqueia C no 1:1.
- **Conta:** A em `/?contato=3`; C na mesma conversa
- **Preparação:** histórico de M-11 pode existir
- **Passos:**
  1. A clica **Bloquear contato**.
  2. Observe o aviso e o composer nos dois lados (recarregue C se o evento não atualizar a página).
- **Resultado esperado:** status “Carla Manual foi bloqueado. O histórico anterior foi preservado. O bloqueio individual não oculta mensagens em grupos compartilhados.” A vê o aviso de quem bloqueou; C vê “Você não pode enviar mensagens para este contato…” Composer desabilitado: “Envios individuais estão interrompidos enquanto o bloqueio estiver ativo.” Histórico antigo continua visível. Sem botão desbloquear no lado de C.
- **Status:** Não executado
- **Observações / captura:**


### M-27 — Efeitos reais do bloqueio

- **ID:** M-27
- **Objetivo:** 1:1 interrompido; grupo compartilhado intacto.
- **Conta:** A e C
- **Preparação:** bloqueio M-26 ativo
- **Passos:**
  1. Em A–C, tente enviar, editar e remover (botões de ação devem sumir com `$bloqueada`).
  2. Tente digitar: não deve haver indicador no outro.
  3. Ambos abrem **Grupo ABC** e A envia `grupo-com-bloqueio`.
  4. Abra `/profile` em A: seção Contatos bloqueados lista Carla.
- **Resultado esperado:** 1:1 sem envio (403 se forçar POST). Grupo ABC entrega a mensagem para C normalmente. Perfil de A lista o bloqueio com botão Desbloquear.
- **Status:** Não executado
- **Observações / captura:**


### M-28 — Desbloquear

- **ID:** M-28
- **Objetivo:** Restaurar o 1:1 (necessário para os casos seguintes com C).
- **Conta:** A
- **Preparação:** header da conversa ou `/profile`
- **Passos:**
  1. **Desbloquear**.
  2. Envie `depois-do-desbloqueio` para C.
- **Resultado esperado:** status “Carla Manual foi desbloqueado.” Composer volta. C recebe. Lista de bloqueados vazia de novo.
- **Status:** Não executado
- **Observações / captura:**


---

## 9. Queda e recuperação da conexão

### M-29 — Disconnect Pusher, rascunho e reconciliação

- **ID:** M-29
- **Objetivo:** Preservar rascunho local e buscar o que chegou offline.
- **Conta:** A e B em `/?contato=` um do outro
- **Preparação:** DevTools → Console na janela de **A**
- **Passos:**
  1. Em A, digite um rascunho `rascunho-offline` **sem enviar**.
  2. No console de A: `window.__chatDesconectarPusher()`.
  3. Em B, envie `enquanto-a-estava-offline`.
  4. Confira que A **não** recebe na hora e que o rascunho continua no campo.
  5. No console de A: `window.__chatReconectarPusher()`.
- **Resultado esperado:** após reconectar, A insere a mensagem de B (reconciliação por `versao`) sem perder o rascunho. Se a reconciliação falhar, aparece “Não foi possível atualizar o histórico. A conversa pode estar incompleta.”
- **Status:** Não executado
- **Observações / captura:**


---

## 10. Desktop, celular, gaveta, modal, teclado e foco

### M-30 — Desktop (≥900 px)

- **ID:** M-30
- **Objetivo:** Lista e conversa lado a lado; modal de grupo.
- **Conta:** A
- **Preparação:** janela larga (≥900 px). http://127.0.0.1:8002/?contato=2
- **Passos:**
  1. Confira sidebar visível, sem botão de gaveta no fluxo principal.
  2. Abra Novo grupo, Tab pelos campos, Escape/`×`/Cancelar.
  3. Ao fechar, o foco volta para **Novo grupo**.
- **Resultado esperado:** layout em duas colunas. Enter no composer envia (não é teclado virtual).
- **Status:** Não executado
- **Observações / captura:**


### M-31 — Celular (≤735 px)

- **ID:** M-31
- **Objetivo:** Gaveta, backdrop, teclado e foco do modal.
- **Conta:** A
- **Preparação:** DevTools device toolbar, largura ≤735 px (ex.: 390×844)
- **Passos:**
  1. A conversa ocupa a tela; abra a lista pelo botão de barras.
  2. Clique no backdrop ou pressione Escape: a gaveta fecha e o foco volta ao botão.
  3. Abra Novo grupo a partir da gaveta: a gaveta fecha, o diálogo abre, foco no nome.
  4. Feche o modal: a gaveta **reabre** e o foco volta a Novo grupo.
  5. No composer, Enter **não** envia; use o botão avião. `Shift+Enter` ainda quebra linha.
- **Status:** Não executado
- **Observações / captura:**


---

## 11. Fluxos HTML sem JavaScript

Desligue JS (ou use um perfil com JS blocked). Entre como **A**. Não exclua contas aqui.

### M-32 — Chat e auth sem JS

- **ID:** M-32
- **Objetivo:** O que o HTML puro cobre, e o que some.
- **Conta:** A (e opcionalmente login/register guest)
- **Preparação:** JS desligado. http://127.0.0.1:8002/login
- **Passos:**
  1. Login e `/` funcionam (formulários).
  2. Abra Carla, envie `sem-js` pelo POST do composer.
  3. Abra `/?criar_grupo=1`, crie `Grupo Sem JS` com um membro, Cancelar volta sem o query param. No grupo, abra `/?grupo={id}&gerenciar_membros=1`, adicione/remova e use **Fechar**.
  4. Em uma mensagem **sua**, use Remover: deve ir a `/mensagens/{id}/confirmacao-remocao`. Cancele (link Voltar). Depois confirme a remoção de uma mensagem descartável.
  5. Clique em Editar: **não** deve haver prompt nem PATCH.
  6. “Carregar mensagens anteriores” não pagina.
  7. Em `/profile`, Delete Account **não** abre o modal Alpine.
  8. Relógio em tempo real, presença, digitação e rascunho `sessionStorage` não existem.
- **Resultado esperado:** auth, envio, grupos, bloqueio, confirmação de remoção e perfil (exceto modal de exclusão) operam por navegação completa. Sem JavaScript, edição, paginação extra, Echo e rascunho permanecem inativos.
- **Status:** Não executado
- **Observações / captura:**


---

## 12. Logout entre abas e exclusão

Últimos casos. Religue o JavaScript.

### M-33 — Logout entre abas

- **ID:** M-33
- **Objetivo:** Uma aba avisa as outras do mesmo perfil.
- **Conta:** A
- **Preparação:** duas abas do **mesmo** navegador/perfil em `/` (A). Não use a janela da conta B.
- **Passos:**
  1. Na aba 1, clique **Sair**.
  2. Observe a aba 2 sem clicar em nada.
- **Resultado esperado:** ambas vão para `/login`. Rascunhos de A nesta origem são limpos. A janela da conta B (outro perfil) **permanece** logada.
- **Status:** Não executado
- **Observações / captura:**


### M-34 — Excluir a conta descartável F

- **ID:** M-34
- **Objetivo:** Exclusão permanente só da F. Não exclua A–E.
- **Conta:** F (senha `password` se M-07 foi feito; senão a de M-06)
- **Preparação:** JS ligado. http://127.0.0.1:8002/profile
- **Passos:**
  1. Delete Account → modal Alpine.
  2. Cancele.
  3. Abra de novo, senha errada.
  4. Senha correta e confirme.
  5. Tente login com F.
  6. Como A, confira que F sumiu da lista.
- **Resultado esperado:** cancelar não apaga. Senha errada reabre o modal com erro `userDeletion`. Sucesso: logout, redirect `/` → login. F não autentica mais. **Não execute isto em A, B ou C.**
- **Status:** Não executado
- **Observações / captura:**


---

## Limitações conhecidas

1. **`MustVerifyEmail` desativado** — o cadastro entra no chat sem verificar e-mail. `/verify-email` só aparece se a URL for aberta e o usuário ainda não tiver `email_verified_at`.
2. **Exclusão de conta depende de Alpine** — sem JS o botão não abre o formulário DELETE.
3. **Edição de mensagem é só JS** (`prompt`). Sem JS o botão não envia.
4. **“Carregar mensagens anteriores” é só JS.**
5. **`/confirm-password` não está ligado** a nenhum fluxo (nenhum `password.confirm`).
6. **`welcome.blade.php` e `dashboard.blade.php` não têm rota.**
7. Textos Breeze do perfil/login em inglês; o chat em português.
8. O isolado define `PHP_CLI_SERVER_WORKERS=1` no processo. Se o `.env` já exportar outro valor, o diagnóstico em `/e2e/diagnostico` pode mostrar o valor do ambiente. Com `CACHE_STORE=array` e mais de um worker, o throttle de login (5 tentativas) pode ficar irregular.
9. Rascunho não atravessa abas/navegadores (`sessionStorage`).
10. Anexos recusados em grupo por regra de negócio, não só por UI.
