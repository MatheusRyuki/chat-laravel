# Chat

Aplicação Laravel de chat em tempo real (em construção). Há autenticação, contatos reais, envio HTTP com persistência no MySQL `chat` e atualização da conversa aberta via Pusher Channels.

## Escopo atual

- Cadastro, login e logout (Laravel Breeze / Blade, textos em pt-BR).
- Tela de chat autenticada com o nome da sessão no topo da sidebar.
- Contatos = demais usuários cadastrados no banco `chat`, excluindo o autenticado (escolha deste estudo, sem tabela de amizade).
- Cada contato mostra nome, e-mail e avatar padrão (iniciais) quando não há imagem.
- Sem outros usuários: **Nenhum outro usuário cadastrado.**
- Seleção por `/?contato={id}`: destaca o item, atualiza o cabeçalho da conversa; sem seleção, **Selecione um contato**.
- Ao escolher outro contato na mesma aba, a área da conversa mostra **Carregando conversa…** até a navegação terminar (sem JavaScript a seleção segue pelo link).
- Id inexistente ou o próprio usuário como interlocutor: HTTP 404.
- Envio por `POST /mensagens` quando há contato selecionado. O remetente sai só da sessão; o destinatário precisa existir e ser outra pessoa.
- Conteúdo: texto simples, sem vazio/só espaços. Limite de **1000 caracteres** (decisão deste estudo), na interface (`maxlength`) e no servidor.
- Histórico só do par autenticado ↔ contato, ordem cronológica (desempate por ID). Recebidas à esquerda, enviadas à direita. HTML escapado; quebras de linha preservadas.
- Sem mensagens: **Nenhuma mensagem nesta conversa.**
- Após gravar, o evento `MensagemEnviada` (`ShouldBroadcastNow`) publica só nos canais privados do remetente e do destinatário. Se o Pusher falhar, a mensagem permanece salva.
- A conversa aberta escuta `.mensagem.enviada` no Echo já existente, reconcilia com `GET /mensagens?contato={id}` ao assinar/reconectar e evita duplicar pelo ID persistido.
- Presença: ponto verde = **Online** (conectado a `presenca.chat`); cinza = **Offline**; antes da assinatura = **Presença a confirmar**; se a conexão do observador cair = **Presença indisponível**. O seletor de status do próprio perfil permanece só como UI estática do template.
- Broadcasting via Pusher Channels: Echo só na tela autenticada do chat. Mensagens no canal privado `App.Models.User.{id}`; presença no canal `presenca.chat` (`presence-presenca.chat`).
- Comando local `chat:diagnostico-pusher` para disparar um evento técnico fictício (sem rota pública).

## Requisitos

- Docker e Docker Compose
- Não é necessário PHP, Composer ou Node no host depois que o Sail estiver no ar
- Conta Pusher Channels (credenciais no `.env`; o secret nunca vai para o Vite)

## Identificação Docker

| Recurso | Nome |
| --- | --- |
| Projeto Compose | `chat` |
| Container da aplicação | `chat-app` |
| Imagem da aplicação | `chat-app:8.5` (PHP 8.5) |
| Container do banco | `chat-mysql` (MySQL 8.4) |
| Banco | `chat` |
| Rede | `chat` |
| Volume | `chat-mysql` |

Portas no host: aplicação **8000**, MySQL **33060**, Vite **5173**. O Proesc permanece em **8001**.

## Instalação com Sail

Na raiz do repositório, se ainda não houver `vendor/`:

```bash
docker run --rm -u "$(id -u):$(id -g)" \
  -v "$PWD":/opt -w /opt \
  laravelsail/php85-composer:latest \
  composer install --ignore-platform-reqs
```

Copie o ambiente e suba os containers:

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

Defina `DB_PASSWORD` no `.env` (não versionado) antes do `up`. Os demais valores de banco já apontam para o serviço `mysql` e o database `chat`.

## Pusher Channels

Preencha no `.env` (não versionado):

```env
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=
VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
```

- Só `VITE_PUSHER_APP_KEY` e `VITE_PUSHER_APP_CLUSTER` vão para o frontend (chave pública e cluster).
- **Nunca** exponha `PUSHER_APP_SECRET` em `VITE_*`, no repositório ou no Vite.
- Sem chave/cluster, o Echo **não conecta**; login, chat e logout seguem normais.
- Depois de preencher as credenciais, reconstrua o frontend (o Vite embute a chave pública no build):

```bash
./vendor/bin/sail npm run build
```

O Echo carrega apenas em `resources/views/chat/index.blade.php` (`resources/js/chat.js`), para não abrir conexão no login.

### Diagnóstico

Com o chat autenticado aberto no navegador:

```bash
./vendor/bin/sail artisan chat:diagnostico-pusher yoshi@example.com
```

O argumento aceita ID numérico ou e-mail. O evento `DiagnosticoPusher` usa `ShouldBroadcastNow` (prova sem worker de fila). Confirme no console do browser a assinatura do canal `private-App.Models.User.{id}` e o payload.

Se eventos futuros usarem `ShouldBroadcast` (enfileirado), aí sim:

```bash
./vendor/bin/sail artisan queue:work
```

Esta prova e o evento `MensagemEnviada` **não** dependem de worker.

## Comandos

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan test
./vendor/bin/sail npm run build
./vendor/bin/sail pint
./vendor/bin/sail composer check-platform-reqs
./vendor/bin/sail artisan chat:diagnostico-pusher {id-ou-email}
./vendor/bin/sail down
```

Os testes de PHPUnit usam SQLite em memória, isolados do MySQL de desenvolvimento. A autorização dos canais privado e de presença é testada localmente (`/broadcasting/auth`), sem HTTP à nuvem do Pusher.

## Presença

Neste projeto, **Online** significa que a pessoa está conectada ao canal de presença do chat. Não é o item “Online” do seletor do próprio perfil.

- Qualquer usuário autenticado pode assinar `presenca.chat`. O servidor identifica o membro pela sessão e devolve só `id` e `name`.
- A lista inicial (`here`) substitui o estado anterior; `joining` e `leaving` atualizam a sidebar e o cabeçalho do contato selecionado, sem recarregar a página.
- Mensagens **não** passam por esse canal. Continuam só em `private-App.Models.User.{id}`.
- Duas abas da mesma conta compartilham o mesmo `user_id` no Pusher: fechar uma aba não marca a pessoa como offline enquanto a outra permanecer. Depois da última conexão, o estado segue a detecção do Pusher; quedas abruptas (fechar o navegador, perda de rede) **não** precisam aparecer na hora.
- No logout, a aba encerra as assinaturas do Echo. Outras abas da mesma origem recebem um sinal em `localStorage` e também desconectam, para não ficarem ligadas com a sessão já encerrada.
- Na reconexão, a lista atual de participantes substitui o estado antigo. Não há segunda instância do Echo nem listeners duplicados de mensagem/presença.

### O que os testes cobrem e o que é Pusher de verdade

- PHPUnit (`/broadcasting/auth`): simulado. Recusa visitante, autoriza autenticado e confere o `channel_data` (HMAC local, SQLite).
- Navegador com Echo: conexão real ao Pusher (chave pública + cluster no Vite). Entrada, saída, duas abas e reconexão só se comprovam aí.

### Limitação observada na ferramenta de browser

Abas do browser embutido compartilham cookies da mesma origem. Não dá para manter Yoshi e Carla autenticados ao mesmo tempo nessa ferramenta. Para entrada/saída entre contas, use o roteiro manual abaixo (janela anônima ou segundo navegador).

### Roteiro manual (duas contas)

1. Navegador A: entre com Yoshi, abra a conversa com Carla. Os indicadores começam em **Presença a confirmar** e, após a assinatura, Carla fica **Offline** se ela ainda não entrou.
2. Navegador B (anônimo): entre com Carla e abra o chat. No A, Carla passa a **Online** sem recarregar.
3. No B, abra uma segunda aba do chat com Carla. Feche só essa aba: no A, Carla permanece **Online**.
4. No B, saia (Sair) ou feche todas as abas de Carla. No A, Carla vai para **Offline** quando o Pusher detectar a saída (pode haver atraso em fechamento abrupto).
5. Confirme que uma mensagem de Carla ainda chega **uma vez** na conversa aberta de Yoshi e que o rascunho no campo de envio permanece.

## URLs

- Aplicação: http://localhost:8000
- Login: http://localhost:8000/login
- Cadastro: http://localhost:8000/register
- Contato selecionado: http://localhost:8000/?contato={id}
- Histórico JSON (autenticado, só o par): http://localhost:8000/mensagens?contato={id}

Visitantes são redirecionados ao login. O chat em `/` exige sessão autenticada.
