# Chat

Um chat feito com Laravel, com conversas individuais, grupos e envio de imagens. As mensagens ficam salvas no MySQL e chegam aos outros participantes em tempo real pelo Pusher Channels.

A interface usa Blade, Tailwind CSS e JavaScript com Laravel Echo. O ambiente Docker inclui PHP 8.5 e MySQL 8.4.

![Chat no desktop, com contas fictícias](docs/screenshots/desktop.png)

Outras telas, incluindo a versão para celular, estão em [docs/screenshots](docs/screenshots).

## O que dá para fazer

- Criar uma conta e conversar com outros usuários cadastrados.
- Criar grupos de texto e, como criador, adicionar ou remover participantes.
- Enviar uma imagem JPEG ou PNG de até 2 MB em uma conversa individual.
- Editar as próprias mensagens ou removê-las para todos os participantes.
- Ver mensagens não lidas, quem está online e quem está digitando.
- Consultar mensagens antigas e alternar entre conversas sem perder o rascunho da aba.
- Bloquear contatos pelo perfil.

As mensagens de texto têm limite de 1.000 caracteres. No computador, Enter envia e Shift+Enter insere uma quebra de linha; no celular, o envio é pelo botão.

Novos membros de um grupo podem ler o histórico anterior. O bloqueio de um contato interrompe as mensagens individuais nos dois sentidos, mas mantém o histórico e as conversas em grupos compartilhados.

## Como rodar

Você precisa de Git, Docker com Docker Compose e uma conta no Pusher Channels para as atualizações em tempo real. Os comandos abaixo usam Bash: no Windows, execute pelo WSL2 com a integração do Docker habilitada.

PHP, Composer e Node são executados nos containers durante esta instalação.

### 1. Baixe o projeto e configure o ambiente

Os passos abaixo são para uma instalação nova. Se você já tem o projeto configurado, use a pasta existente e preserve o seu `.env`.

```bash
git clone https://github.com/MatheusRyuki/chat-laravel.git
cd chat-laravel
cp .env.example .env
```

Abra o `.env` e defina uma senha em `DB_PASSWORD` antes de iniciar os containers. Mantenha `DB_HOST=mysql`, `DB_DATABASE=chat` e `DB_USERNAME=chat`, que já correspondem à configuração do Docker.

Preencha também as credenciais do seu aplicativo Pusher Channels:

```env
PUSHER_APP_ID=seu-app-id
PUSHER_APP_KEY=sua-chave
PUSHER_APP_SECRET=seu-segredo
PUSHER_APP_CLUSTER=seu-cluster
```

O arquivo de exemplo já configura `BROADCAST_CONNECTION=pusher` e as variáveis `VITE_PUSHER_APP_KEY` e `VITE_PUSHER_APP_CLUSTER`. O segredo fica apenas no servidor; não crie uma variável `VITE_*` para ele. O `.env` não é versionado.

Sem configurar o Pusher, você consegue cadastrar usuários e enviar mensagens, mas os outros participantes precisam atualizar a página para vê-las.

### 2. Instale as dependências PHP

Na primeira instalação, use o container do Composer para criar a pasta `vendor/`, que inclui o Laravel Sail:

```bash
docker run --rm -u "$(id -u):$(id -g)" \
  -v "$PWD":/opt -w /opt \
  laravelsail/php85-composer:latest \
  composer install --ignore-platform-reqs
```

### 3. Inicie a aplicação

```bash
./vendor/bin/sail up -d --wait
./vendor/bin/sail composer check-platform-reqs
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

O primeiro comando aguarda o banco ficar disponível. A verificação do Composer confere os requisitos PHP no container da aplicação.

Abra [http://localhost:8000](http://localhost:8000) e crie uma conta. Para experimentar a conversa, cadastre outra conta em uma janela anônima ou em outro navegador.

As portas padrão são `8000` para a aplicação, `33060` para o MySQL e `5173` para o Vite. Se a porta da aplicação estiver ocupada, ajuste `APP_PORT` e `APP_URL` no `.env` antes de iniciar os containers. Para mudar a porta do MySQL no computador, use `FORWARD_DB_PORT`.

## Desenvolvimento

Execute os comandos na raiz do projeto:

| Tarefa | Comando |
| --- | --- |
| Iniciar os containers | `./vendor/bin/sail up -d` |
| Acompanhar mudanças no frontend com Vite | `./vendor/bin/sail npm run dev` |
| Gerar os arquivos CSS e JavaScript | `./vendor/bin/sail npm run build` |
| Aplicar novas migrations | `./vendor/bin/sail artisan migrate` |
| Formatar o código PHP | `./vendor/bin/sail pint` |
| Parar os containers | `./vendor/bin/sail down` |

Mantenha o comando do Vite aberto enquanto estiver editando o frontend. Se alterar a chave ou o cluster do Pusher, reinicie o Vite ou gere o build novamente.

Em um banco que já contém conversas, use `migrate` para atualizar o esquema. Os comandos `migrate:fresh` e `migrate:refresh` apagam dados. O banco é armazenado no volume Docker `chat-mysql`, preservado por `sail down`.

## Testes

Para executar os testes PHP:

```bash
./vendor/bin/sail artisan test
```

Eles usam SQLite em memória e simulam os eventos de broadcast, sem acessar o banco MySQL da aplicação ou o serviço do Pusher.

Os testes de navegador usam Playwright e Pusher real, com um servidor separado na porta `8002`. Eles precisam de ferramentas instaladas fora dos containers; veja a preparação e os comandos em [Testes automatizados](docs/testes.md).

Para testar as telas por conta própria, siga o [roteiro manual](docs/testes-manuais.md), que prepara contas e dados fictícios em um ambiente separado.

## Para conhecer o código

As regras de conversas, leitura, anexos e bloqueios ficam em [app/Services](app/Services). As páginas são renderizadas por Blade em [resources/views](resources/views), e o comportamento do chat no navegador fica em [resources/js/chat.js](resources/js/chat.js).

O documento de [arquitetura](docs/arquitetura.md) explica como as mensagens são salvas, como os eventos chegam aos participantes e onde ficam as verificações de acesso.
