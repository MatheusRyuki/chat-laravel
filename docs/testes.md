# Testes automatizados

O projeto tem testes PHP, testes de navegador e uma verificação das migrations no MySQL. Cada um usa seus próprios dados. Os comandos abaixo devem ser executados na raiz do repositório, depois da [instalação](../README.md).

## Testes PHP

```bash
./vendor/bin/sail artisan test
```

A configuração em [phpunit.xml](../phpunit.xml) usa SQLite em memória, credenciais fictícias do Pusher e eventos simulados. Esses testes verificam regras de negócio, acesso às rotas, renderização das páginas e migrations sem chamar o serviço do Pusher.

Para executar apenas uma parte da suíte:

```bash
./vendor/bin/sail artisan test --filter=EvolucaoChatTest
```

## Testes de navegador

O Playwright abre o Chrome e testa a aplicação com Pusher real. O servidor de teste usa a porta `8002`, enquanto a aplicação no Sail usa `8000`.

### Preparar o ambiente local

A [configuração do Playwright](../tests/e2e/playwright.config.ts) inicia o servidor chamando `php artisan serve` pelo Bash. Por isso, além dos containers, o ambiente em que você executa os testes precisa de:

- PHP com as extensões exigidas pelo projeto, incluindo SQLite para o banco de teste e GD para as imagens. Use PHP 8.5, a mesma versão do Sail; as dependências travadas atualmente exigem pelo menos PHP 8.4.1.
- Composer para conferir os requisitos do PHP local.
- Node e npm para executar o Playwright e gerar os arquivos do frontend. Use Node 24, a versão configurada no Sail.
- Google Chrome e as bibliotecas necessárias para executá-lo nesse ambiente.

No Windows, execute no WSL2. PHP, Node e Chrome precisam estar disponíveis dentro dele.

Confira os requisitos e prepare o frontend:

```bash
composer check-platform-reqs
npm ci
npm run build
```

Se o Chrome ainda não estiver instalado no ambiente de teste:

```bash
npx playwright install chrome
```

As credenciais `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET` e `PUSHER_APP_CLUSTER` precisam estar preenchidas no `.env`. Use um aplicativo Pusher destinado a desenvolvimento. Se mudar a chave ou o cluster, gere o build novamente.

### Executar

Deixe a porta `8002` livre. Se iniciou o ambiente do roteiro manual, encerre-o antes:

```bash
tests/e2e/parar-manual.sh
```

Execute a suíte:

```bash
npm run test:e2e
```

O [script de inicialização](../tests/e2e/iniciar.sh) recria `tests/e2e/chat-e2e.sqlite`, aplica as migrations e cadastra contas fictícias. Esse mesmo SQLite é usado pelo roteiro manual, portanto os dados preparados nele serão substituídos. Não execute as duas modalidades ao mesmo tempo.

O ambiente de teste usa o cookie `chat_e2e_session`, sessões em `tests/e2e/storage/sessions`, anexos em `tests/e2e/storage/anexos` e canais Pusher com prefixo `e2e-`. As mensagens não são gravadas no MySQL da aplicação.

A suíte usa um worker do Playwright. O servidor PHP pode atender várias requisições ao mesmo tempo: o script usa oito workers por padrão e passa `--no-reload` ao Artisan. Essa flag permite que `PHP_CLI_SERVER_WORKERS` seja respeitada, evitando que a autenticação dos canais espere o envio de uma mensagem terminar.

### Onde estão os cenários

| Área | Arquivos |
| --- | --- |
| Autenticação e perfil | [Auth](../tests/Feature/Auth), [ProfileTest](../tests/Feature/ProfileTest.php) |
| Envio, validação e privacidade das conversas | [MensagemTest](../tests/Feature/MensagemTest.php), [BroadcastChannelTest](../tests/Feature/BroadcastChannelTest.php) |
| Lista, leitura, histórico, edição, grupos, anexos e bloqueios | [EvolucaoChatTest](../tests/Feature/EvolucaoChatTest.php) |
| Formatação de mensagens e horários | [Testes unitários](../tests/Unit) |
| Instalação limpa e migração de mensagens antigas | [MigracaoEvolucaoChatTest](../tests/Feature/MigracaoEvolucaoChatTest.php) |
| Interações na interface, layout e uso sem JavaScript | [evolucao.spec.ts](../tests/e2e/evolucao.spec.ts) |
| Entrega de eventos, digitação, remoção de membros e reconexão | [tempo-real.spec.ts](../tests/e2e/tempo-real.spec.ts) |

Os cenários de tempo real distinguem eventos recebidos pelo Pusher de mudanças recuperadas por HTTP. O cliente registra essas informações em `window.__chatDiagnostico.eventosPusher` e `window.__chatDiagnostico.reconciliacoes`. Assim, o teste de entrega ao vivo não passa apenas porque uma consulta HTTP atualizou a tela.

## Migrations no MySQL

Com os containers do Sail em execução e o `.env` usando o banco e o usuário `chat`:

```bash
bash tests/e2e/verificar-migracoes-mysql.sh
```

O script recria duas bases reservadas para teste, `chat_e2e_migracoes` e `chat_e2e_limpa`. Não use esses nomes para guardar dados que deseja manter.

Uma base verifica a atualização do esquema antigo, e a outra verifica a instalação do zero. Ao terminar com sucesso, o script apaga ambas. Ele consulta o banco `chat` para exibir seu estado, mas não o altera. Se a execução for interrompida, as bases de teste podem permanecer até a próxima execução.

## O que ainda precisa de teste manual

A suíte simula uma aba oculta no Chromium, mas não testa uma janela minimizada pelo sistema operacional. Também não cobre teclado virtual de um celular físico nem composição de texto por um método de entrada do sistema (IME).

O [roteiro manual](testes-manuais.md) descreve as contas, os passos e os resultados esperados para conferir as telas. Seus campos de status devem ser preenchidos durante a execução; a existência de um teste automatizado não significa que o caso manual já foi executado.
