<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $selecionado ? $selecionado->name.' · '.config('app.name') : ($conversa?->nome ? $conversa->nome.' · '.config('app.name') : config('app.name')) }}</title>
    <link rel="stylesheet prefetch" href="https://cdnjs.cloudflare.com/ajax/libs/meyer-reset/2.0/reset.min.css">
    <link rel="stylesheet prefetch"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.2/css/font-awesome.min.css">
    <link rel="stylesheet" href="{{ asset('assets/style.css') }}">
    @vite(['resources/js/chat.js'])
</head>

<body>
    @php
        $user = auth()->user();
        $userAvatar = avatar_data_uri($user->name);
        $selecionadoAvatar = $selecionado ? avatar_data_uri($selecionado->name) : ($conversa?->eGrupo() ? avatar_data_uri((string) $conversa->nome) : null);
        $conversaAberta = $conversa !== null;
        $bloqueada = $bloqueadoPorMim || $bloqueadoPorEle;
        $eGrupo = $conversa?->eGrupo() ?? false;
        $idsAutorizados = $itensLista->pluck('conversa_id')->filter()->values();
    @endphp

    <div id="frame"
        data-user-id="{{ $user->id }}"
        data-fuso="{{ config('app.timezone') }}"
        data-locale="{{ str_replace('_', '-', app()->getLocale()) }}"
        data-login-url="{{ route('login') }}"
        data-prefixo-canal="{{ prefixo_canal_broadcast() }}"
        data-expira-digitacao="{{ (int) config('chat.digitacao_expira_em') }}"
        data-janela="{{ (int) $janelaHistorico }}"
        @if ($selecionado) data-contato-id="{{ $selecionado->id }}" @endif
        @if ($conversa) data-conversa-id="{{ $conversa->id }}" data-versao-conversa="{{ $conversa->versao }}" data-tipo-conversa="{{ $conversa->tipo->value }}" @endif
        @if ($eGrupo) data-grupo-id="{{ $conversa->id }}" @endif
        @if ($bloqueada) data-bloqueada="1" @endif
        data-conversas-autorizadas="{{ $idsAutorizados->implode(',') }}">
        <button type="button" id="sidebar-backdrop" hidden tabindex="-1" aria-label="Fechar lista de contatos"></button>
        <div id="sidepanel">
            <div id="profile">
                <div class="wrap">
                    <img id="profile-img" src="{{ $userAvatar }}" class="aguardando" alt="{{ $user->name }}" data-presenca-usuario="{{ $user->id }}" />
                    <p>{{ $user->name }}</p>
                </div>
            </div>
            <button type="button" id="sidebar-toggle" aria-expanded="false" aria-controls="sidepanel">
                <i class="fa fa-bars" aria-hidden="true"></i>
                <span class="sr-only">Abrir lista de contatos</span>
            </button>
            <hr>
            <div id="contacts">
                @if ($itensLista->isEmpty())
                    <p class="contacts-empty">Nenhum outro usuário cadastrado.</p>
                @else
                    <ul>
                        @foreach ($itensLista as $item)
                            <li class="contact{{ $item['ativa'] ? ' active' : '' }}"
                                data-tipo="{{ $item['tipo'] }}"
                                @if ($item['conversa_id']) data-conversa-id="{{ $item['conversa_id'] }}" @endif
                                @if ($item['contato_id']) data-contato-id="{{ $item['contato_id'] }}" @endif
                                @if ($item['ultima_id']) data-ultima-id="{{ $item['ultima_id'] }}" @endif
                                data-versao="{{ $item['versao'] }}">
                                <a href="{{ $item['url'] }}"@if ($item['ativa']) aria-current="page"@endif>
                                    <div class="wrap">
                                        @if ($item['presenca_usuario_id'])
                                            <span class="contact-status aguardando" data-presenca-usuario="{{ $item['presenca_usuario_id'] }}" role="status" aria-label="Presença a confirmar"></span>
                                        @else
                                            <span class="contact-status grupo" aria-hidden="true"></span>
                                        @endif
                                        <img src="{{ avatar_data_uri($item['nome']) }}" alt="{{ $item['nome'] }}" />
                                        <div class="meta">
                                            <p class="name">
                                                {{ $item['nome'] }}
                                                @if ($item['nao_lidas'] > 0)
                                                    <span class="nao-lidas" aria-label="{{ $item['nao_lidas'] }} não lidas">{{ $item['nao_lidas'] }}</span>
                                                @else
                                                    <span class="nao-lidas" hidden>0</span>
                                                @endif
                                            </p>
                                            <p class="previa">{{ $item['previa'] }}</p>
                                        </div>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div id="bottom-bar">
                <details id="criar-grupo">
                    <summary>Novo grupo</summary>
                    <form method="POST" action="{{ route('grupos.store') }}" class="formulario-grupo">
                        @csrf
                        <label for="nome-grupo">Nome do grupo</label>
                        <input id="nome-grupo" type="text" name="nome" maxlength="80" required value="{{ old('nome') }}">
                        <p class="ajuda-grupo">Novos membros podem consultar o histórico do grupo.</p>
                        <fieldset>
                            <legend>Participantes</legend>
                            @foreach ($usuariosParaGrupo as $candidato)
                                <label>
                                    <input type="checkbox" name="membros[]" value="{{ $candidato->id }}">
                                    {{ $candidato->name }}
                                </label>
                            @endforeach
                        </fieldset>
                        <button type="submit">Criar grupo</button>
                    </form>
                </details>
                <a class="link-conta" href="{{ route('profile.edit') }}">Conta</a>
                <form id="formulario-sair" method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">
                        <i class="fa fa-sign-out" aria-hidden="true"></i>
                        <span>Sair</span>
                    </button>
                </form>
            </div>
        </div>
        <div class="content">
            <div class="coluna-cabecalho">
            <div class="contact-profile">
                @if ($selecionado)
                    <img src="{{ $selecionadoAvatar }}" alt="{{ $selecionado->name }}" />
                    <div class="cabecalho-conversa">
                        <p>{{ $selecionado->name }}</p>
                        <p class="contato-email">{{ $selecionado->email }}</p>
                        <span class="presenca-contato aguardando" data-presenca-usuario="{{ $selecionado->id }}" role="status" aria-label="Presença a confirmar">
                            <span class="presenca-ponto" aria-hidden="true"></span>
                            <span class="presenca-texto">Presença a confirmar</span>
                        </span>
                        <p id="indicador-digitacao" class="indicador-digitacao" hidden></p>
                    </div>
                    @unless ($bloqueadoPorEle)
                        @if ($bloqueadoPorMim)
                            @php $bloqueioAtual = $user->bloqueiosFeitos->firstWhere('bloqueado_id', $selecionado->id); @endphp
                            <form method="POST" action="{{ $bloqueioAtual ? route('bloqueios.destroy', $bloqueioAtual) : route('bloqueios.store') }}" class="formulario-bloqueio">
                                @csrf
                                @if ($bloqueioAtual)
                                    @method('DELETE')
                                    <button type="submit">Desbloquear</button>
                                @else
                                    <input type="hidden" name="user_id" value="{{ $selecionado->id }}">
                                    <button type="submit">Bloquear contato</button>
                                @endif
                            </form>
                        @else
                            <form method="POST" action="{{ route('bloqueios.store') }}" class="formulario-bloqueio">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $selecionado->id }}">
                                <button type="submit">Bloquear contato</button>
                            </form>
                        @endif
                    @endunless
                @elseif ($eGrupo)
                    <img src="{{ $selecionadoAvatar }}" alt="{{ $conversa->nome }}" />
                    <div class="cabecalho-conversa">
                        <p>{{ $conversa->nome }}</p>
                        <p class="contato-email">Grupo · {{ $conversa->participantesAtivos->count() }} participantes</p>
                        <p id="indicador-digitacao" class="indicador-digitacao" hidden></p>
                    </div>
                @else
                    <p>Selecione um contato</p>
                @endif
            </div>
            @if (session('status'))
                <p class="aviso-status" role="status">{{ session('status') }}</p>
            @endif
            @if ($bloqueada && $selecionado)
                <p class="aviso-bloqueio" role="status">
                    @if ($bloqueadoPorMim)
                        Você bloqueou este contato. Novos envios e sinais de digitação ficam interrompidos nos dois sentidos até o desbloqueio. O histórico anterior permanece visível. O bloqueio individual não oculta mensagens em grupos compartilhados.
                    @else
                        Você não pode enviar mensagens para este contato. O histórico anterior permanece visível. O bloqueio individual não oculta mensagens em grupos compartilhados.
                    @endif
                </p>
            @endif
            </div>
            <div class="messages" aria-busy="false">
                <div id="conversation-loader" class="conversation-loader" hidden role="status" aria-live="polite" aria-atomic="true">
                    <i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                    <p>Carregando conversa…</p>
                </div>
                <p id="aviso-sincronizacao" class="aviso-sincronizacao" hidden role="status">Não foi possível atualizar o histórico. A conversa pode estar incompleta.</p>
                @if ($conversaAberta && $temAnteriores)
                    <button type="button" id="carregar-anteriores" class="carregar-anteriores">Carregar mensagens anteriores</button>
                @else
                    <button type="button" id="carregar-anteriores" class="carregar-anteriores" hidden>Carregar mensagens anteriores</button>
                @endif
                @if (($selecionado || $eGrupo) && $mensagens->isEmpty())
                    <p class="messages-empty">Nenhuma mensagem nesta conversa.</p>
                @endif
                <ul id="lista-mensagens">
                    @foreach ($mensagens as $mensagem)
                        @php
                            $enviada = $mensagem->remetente_id === $user->id;
                            $horarioIso = $mensagem->created_at?->toIso8601String();
                            $horarioLegivel = formatar_horario_mensagem($mensagem->created_at);
                            $avatarMensagem = $enviada ? $userAvatar : avatar_data_uri($mensagem->remetente?->name ?? ($selecionado->name ?? (string) $conversa?->nome));
                        @endphp
                        <li class="{{ $enviada ? 'replies' : 'sent' }}{{ $mensagem->foiRemovida() ? ' removida' : '' }}"
                            data-mensagem-id="{{ $mensagem->id }}"
                            data-created-at="{{ $horarioIso }}"
                            data-versao="{{ $mensagem->versao }}"
                            data-remetente-id="{{ $mensagem->remetente_id }}">
                            <img src="{{ $avatarMensagem }}" alt="">
                            <div class="mensagem-corpo">
                                @if ($eGrupo)
                                    <span class="remetente-nome">{{ $mensagem->remetente?->name }}</span>
                                @endif
                                @if ($mensagem->foiRemovida())
                                    <p class="mensagem-removida">Mensagem removida</p>
                                @else
                                    <p>{!! \App\Support\FormatadorMensagem::paraHtml($mensagem->conteudo) !!}</p>
                                    @if ($mensagem->temAnexo())
                                        <a class="anexo-mensagem" href="{{ $mensagem->urlAnexo() }}" target="_blank" rel="noopener noreferrer">
                                            <img src="{{ $mensagem->urlAnexo() }}" alt="Imagem enviada">
                                        </a>
                                    @endif
                                    @if ($mensagem->foiEditada())
                                        <span class="mensagem-editada">Editada</span>
                                    @endif
                                @endif
                                @if ($horarioLegivel !== '')
                                    <time class="mensagem-horario" datetime="{{ $horarioIso }}">{{ $horarioLegivel }}</time>
                                @endif
                                @if ($enviada && ! $mensagem->foiRemovida() && ! $bloqueada)
                                    <div class="acoes-mensagem">
                                        <button type="button" class="editar-mensagem" data-mensagem-id="{{ $mensagem->id }}">Editar</button>
                                        <form method="GET" action="{{ route('mensagens.confirmacao-remocao', $mensagem) }}" class="formulario-remover">
                                            <button type="submit" class="remover-mensagem">Remover</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div id="anuncio-mensagens" class="sr-only" aria-live="polite" aria-atomic="true"></div>
            </div>
            <div class="message-input">
                @if (($selecionado || $eGrupo) && ! $bloqueada)
                    <form id="formulario-mensagem" method="POST" action="{{ route('mensagens.store') }}" enctype="multipart/form-data">
                        @csrf
                        @if ($conversa)
                            <input type="hidden" name="conversa_id" value="{{ $conversa->id }}">
                        @endif
                        @if ($selecionado)
                            <input type="hidden" name="destinatario_id" value="{{ $selecionado->id }}">
                        @endif
                        <div class="wrap">
                            <label class="sr-only" for="campo-conteudo">Mensagem</label>
                            <textarea id="campo-conteudo" name="conteudo" placeholder="Digite sua mensagem…" maxlength="{{ \App\Models\Mensagem::TAMANHO_MAXIMO }}" rows="1">{{ old('conteudo') }}</textarea>
                            @if ($selecionado)
                                <label class="attachment" title="Anexar imagem">
                                    <i class="fa fa-paperclip" aria-hidden="true"></i>
                                    <span class="sr-only">Anexar imagem JPEG ou PNG de até 2 MB</span>
                                    <input id="campo-anexo" type="file" name="anexo" accept="image/jpeg,image/png" hidden>
                                </label>
                            @endif
                            <button class="submit" type="submit" aria-label="Enviar">
                                <i class="fa fa-paper-plane" aria-hidden="true"></i>
                                <span class="sr-only">Enviar</span>
                            </button>
                        </div>
                        <p id="pre-visualizacao-anexo" class="pre-visualizacao-anexo" hidden></p>
                        <p id="erro-envio" class="mensagem-erro" hidden></p>
                        @error('conteudo')
                            <p class="mensagem-erro">{{ $message }}</p>
                        @enderror
                        @error('destinatario_id')
                            <p class="mensagem-erro">{{ $message }}</p>
                        @enderror
                        @error('anexo')
                            <p class="mensagem-erro">{{ $message }}</p>
                        @enderror
                    </form>
                @elseif ($bloqueada)
                    <p id="orientacao-composer">Envios individuais estão interrompidos enquanto o bloqueio estiver ativo.</p>
                    <div class="wrap">
                        <textarea placeholder="Digite sua mensagem…" disabled aria-describedby="orientacao-composer" rows="1"></textarea>
                        <button class="submit" type="button" disabled aria-label="Enviar" aria-describedby="orientacao-composer">
                            <i class="fa fa-paper-plane" aria-hidden="true"></i>
                        </button>
                    </div>
                @else
                    <p id="orientacao-composer">Selecione um contato à esquerda para escrever.</p>
                    <div class="wrap">
                        <textarea placeholder="Digite sua mensagem…" disabled aria-describedby="orientacao-composer" rows="1"></textarea>
                        <button class="submit" type="button" disabled aria-label="Enviar" aria-describedby="orientacao-composer">
                            <i class="fa fa-paper-plane" aria-hidden="true"></i>
                        </button>
                    </div>
                @endif
            </div>
            @if ($eGrupo && $conversa->usuarioECriador($user))
                <div class="gestao-grupo">
                    <p>Gerenciar membros. Novos membros podem consultar o histórico do grupo. Membros removidos perdem o acesso ao histórico e aos eventos futuros.</p>
                    <ul>
                        @foreach ($conversa->participantesAtivos as $participante)
                            <li>
                                {{ $participante->user?->name }}
                                @if ($participante->user_id !== $user->id)
                                    <form method="POST" action="{{ route('grupos.membros.destroy', [$conversa, $participante->user]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit">Remover</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <form method="POST" action="{{ route('grupos.membros.store', $conversa) }}">
                        @csrf
                        <label class="sr-only" for="novo-membro">Adicionar membro</label>
                        <select id="novo-membro" name="user_id" required>
                            <option value="">Adicionar participante</option>
                            @foreach ($usuariosParaGrupo->whereNotIn('id', $conversa->participantesAtivos->pluck('user_id')) as $candidato)
                                <option value="{{ $candidato->id }}">{{ $candidato->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit">Adicionar</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</body>

</html>
