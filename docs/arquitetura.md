# Como o chat funciona

Este documento descreve as principais regras do backend e a comunicação com o navegador. Para instalar o projeto, consulte o [README](../README.md).

## Do envio à atualização da tela

O navegador envia a mensagem por HTTP. O [MensagemController](../app/Http/Controllers/MensagemController.php) valida o acesso à conversa e grava a mensagem no banco. Em seguida, o [PublicadorMensagem](../app/Broadcasting/PublicadorMensagem.php) envia um evento pelo Pusher, e o Laravel Echo atualiza as telas dos participantes.

A gravação não depende da entrega do evento: uma falha no Pusher é registrada em log e não desfaz a mensagem salva. Depois de uma reconexão, o navegador consulta o servidor para recuperar mudanças que não recebeu.

Cada conversa e mensagem tem um campo `versao`. Ele permite recuperar edições e remoções, além de mensagens novas, e evita que um evento atrasado substitua uma versão mais recente na tela.

## Conversas e histórico

Conversas individuais e grupos usam o modelo [Conversa](../app/Models/Conversa.php), com os tipos `individual` e `grupo`. A tabela `conversa_participantes` registra os membros e a última mensagem lida por cada um.

O histórico começa pelas 50 mensagens mais recentes. As anteriores são carregadas por `antes_id`, mantendo a ordem cronológica. A lista de conversas é ordenada pelo envio da última mensagem; editar uma mensagem antiga não muda sua posição.

O [MontadorListaConversas](../app/Services/MontadorListaConversas.php) reúne prévias e contagens de mensagens não lidas sem fazer uma consulta para cada contato. Eventos também atualizam conversas que não estão abertas, incluindo o primeiro envio de um contato.

As migrations que introduziram esse modelo preservam IDs, autoria, conteúdo e datas das mensagens individuais existentes:

- [Criação das conversas e participantes](../database/migrations/2026_09_19_173342_create_conversas_e_evolucao_do_chat_tables.php).
- [Destinatário opcional para mensagens de grupo](../database/migrations/2026_09_19_174921_make_destinatario_id_nullable_on_mensagens.php).

## Canais e acesso aos eventos

Cada usuário recebe eventos em seu canal privado `App.Models.User.{id}`. A autorização em [routes/channels.php](../routes/channels.php) permite que apenas o dono se conecte a esse canal. O canal de presença `presenca.chat` informa quem está online.

Nos grupos, o servidor calcula os destinatários a cada publicação. Assim, um membro removido deixa de receber eventos futuros mesmo que sua conexão continue aberta. Ele também perde o acesso ao histórico. Um membro recém-adicionado pode consultar as mensagens anteriores à sua entrada.

O indicador de digitação segue o mesmo caminho: o navegador avisa o servidor, que publica o estado nos canais dos outros participantes autorizados. O conteúdo do rascunho não é enviado. O intervalo entre avisos e o tempo de expiração ficam em [config/chat.php](../config/chat.php).

A variável `CHAT_PREFIXO_CANAL` permite separar canais de ambientes que compartilham o mesmo aplicativo Pusher. Ela fica vazia no desenvolvimento e recebe `e2e-` nos scripts de teste.

## Mensagens não lidas e rascunhos

O cliente registra a leitura por `POST /leituras`, até o último ID exibido, quando a aba está visível. Consultar o histórico por GET não marca mensagens como lidas.

O campo `ultima_leitura_mensagem_id` de cada participante só avança. Isso impede que uma requisição atrasada de outra aba volte a marcar mensagens antigas como não lidas. A contagem considera apenas mensagens recebidas e ainda não removidas.

Depois da gravação, `localStorage` e `BroadcastChannel` sincronizam a leitura entre abas da mesma conta. Os rascunhos usam `sessionStorage` e ficam restritos à aba em que foram escritos.

## Edição, remoção e anexos

Somente o autor, enquanto tiver acesso à conversa, pode editar ou remover uma mensagem. A remoção preenche `removida_em` e faz o conteúdo aparecer como “Mensagem removida”. Essa mensagem não pode mais ser editada.

Com JavaScript, o navegador pede confirmação antes do DELETE. Sem JavaScript, uma página de confirmação apresenta a mensagem e envia o DELETE apenas quando o usuário confirma.

Os anexos são JPEG ou PNG de até 2 MB, permitidos em conversas individuais. O [ServicoAnexo](../app/Services/ServicoAnexo.php) gera o nome do arquivo e usa o disco privado `anexos`, configurado em [config/filesystems.php](../config/filesystems.php). O Pusher transporta os metadados e a URL autenticada, não o arquivo.

A rota `GET /mensagens/{mensagem}/anexo` verifica se o usuário participa da conversa e se a mensagem continua disponível. O arquivo não fica acessível por uma pasta pública. Na remoção da mensagem, a exclusão física ocorre depois da alteração no banco; falhas nessa limpeza são registradas em log. Se o upload funcionar, mas a gravação da mensagem falhar, o arquivo enviado também é removido.

## Bloqueio de contatos

O [ServicoBloqueio](../app/Services/ServicoBloqueio.php) registra quem bloqueou quem. Só o autor do bloqueio pode desfazê-lo.

A [ConversaPolicy](../app/Policies/ConversaPolicy.php) impede novos envios e avisos de digitação nas conversas individuais bloqueadas, em ambos os sentidos. O histórico continua acessível. O bloqueio não afeta grupos compartilhados nem conversas com outras pessoas.

## Interface e funcionamento sem JavaScript

A página inicial do chat fica em [resources/views/chat/index.blade.php](../resources/views/chat/index.blade.php). O arquivo [resources/js/chat.js](../resources/js/chat.js) cuida dos eventos, do histórico, dos rascunhos e das atualizações da interface.

O envio por formulário, a criação de grupos e a confirmação de remoção funcionam por navegação de páginas mesmo com JavaScript desativado. Atualizações ao vivo, edição na tela, carregamento de mensagens anteriores e rascunhos dependem de JavaScript.

Links HTTP e HTTPS são reconhecidos pelo [FormatadorMensagem](../app/Support/FormatadorMensagem.php) no servidor e pelo código equivalente no cliente. O restante do conteúdo é escapado antes de entrar no HTML.

## Diagnóstico do Pusher

Com as credenciais configuradas, envie um evento técnico para um usuário existente:

```bash
./vendor/bin/sail artisan chat:diagnostico-pusher 1
```

Substitua `1` pelo ID ou e-mail da conta que deseja verificar. O comando publica no canal privado do usuário e informa se o envio foi realizado.
