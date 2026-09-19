<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $selecionado ? $selecionado->name.' · '.config('app.name') : config('app.name') }}</title>
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
        $selecionadoAvatar = $selecionado ? avatar_data_uri($selecionado->name) : null;
    @endphp

    <div id="frame" data-user-id="{{ $user->id }}" data-fuso="{{ config('app.timezone') }}" data-locale="{{ str_replace('_', '-', app()->getLocale()) }}" data-login-url="{{ route('login') }}"@if ($selecionado) data-contato-id="{{ $selecionado->id }}"@endif>
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
                @if ($contatos->isEmpty())
                    <p class="contacts-empty">Nenhum outro usuário cadastrado.</p>
                @else
                    <ul>
                        @foreach ($contatos as $contato)
                            <li class="contact{{ $selecionado?->id === $contato->id ? ' active' : '' }}">
                                <a href="{{ route('dashboard', ['contato' => $contato->id]) }}"@if ($selecionado?->id === $contato->id) aria-current="page"@endif>
                                    <div class="wrap">
                                        <span class="contact-status aguardando" data-presenca-usuario="{{ $contato->id }}" role="status" aria-label="Presença a confirmar"></span>
                                        <img src="{{ avatar_data_uri($contato->name) }}" alt="{{ $contato->name }}" />
                                        <div class="meta">
                                            <p class="name">{{ $contato->name }}</p>
                                            <p class="contato-email">{{ $contato->email }}</p>
                                        </div>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div id="bottom-bar">
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
            <div class="contact-profile">
                @if ($selecionado)
                    <img src="{{ $selecionadoAvatar }}" alt="{{ $selecionado->name }}" />
                    <p>{{ $selecionado->name }}</p>
                    <span class="presenca-contato aguardando" data-presenca-usuario="{{ $selecionado->id }}" role="status" aria-label="Presença a confirmar">
                        <span class="presenca-ponto" aria-hidden="true"></span>
                        <span class="presenca-texto">Presença a confirmar</span>
                    </span>
                @else
                    <p>Selecione um contato</p>
                @endif
            </div>
            <div class="messages" aria-busy="false">
                <div id="conversation-loader" class="conversation-loader" hidden role="status" aria-live="polite" aria-atomic="true">
                    <i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                    <p>Carregando conversa…</p>
                </div>
                <p id="aviso-sincronizacao" class="aviso-sincronizacao" hidden role="status">Não foi possível atualizar o histórico. A conversa pode estar incompleta.</p>
                @if ($selecionado && $mensagens->isEmpty())
                    <p class="messages-empty">Nenhuma mensagem nesta conversa.</p>
                @endif
                <ul id="lista-mensagens">
                    @foreach ($mensagens as $mensagem)
                        @php
                            $enviada = $mensagem->remetente_id === $user->id;
                            $horarioIso = $mensagem->created_at?->toIso8601String();
                            $horarioLegivel = formatar_horario_mensagem($mensagem->created_at);
                        @endphp
                        <li class="{{ $enviada ? 'replies' : 'sent' }}" data-mensagem-id="{{ $mensagem->id }}" data-created-at="{{ $horarioIso }}">
                            <img src="{{ $enviada ? $userAvatar : $selecionadoAvatar }}" alt="">
                            <div class="mensagem-corpo">
                                <p>{!! nl2br(e($mensagem->conteudo)) !!}</p>
                                @if ($horarioLegivel !== '')
                                    <time class="mensagem-horario" datetime="{{ $horarioIso }}">{{ $horarioLegivel }}</time>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div id="anuncio-mensagens" class="sr-only" aria-live="polite" aria-atomic="true"></div>
            </div>
            <div class="message-input">
                @if ($selecionado)
                    <form id="formulario-mensagem" method="POST" action="{{ route('mensagens.store') }}">
                        @csrf
                        <input type="hidden" name="destinatario_id" value="{{ $selecionado->id }}">
                        <div class="wrap">
                            <input type="text" name="conteudo" placeholder="Digite sua mensagem…" maxlength="{{ \App\Models\Mensagem::TAMANHO_MAXIMO }}" value="{{ old('conteudo') }}" required>
                            <button class="submit" type="submit" aria-label="Enviar">
                                <i class="fa fa-paper-plane" aria-hidden="true"></i>
                                <span class="sr-only">Enviar</span>
                            </button>
                        </div>
                        <p id="erro-envio" class="mensagem-erro" hidden></p>
                        @error('conteudo')
                            <p class="mensagem-erro">{{ $message }}</p>
                        @enderror
                        @error('destinatario_id')
                            <p class="mensagem-erro">{{ $message }}</p>
                        @enderror
                    </form>
                @else
                    <p id="orientacao-composer">Selecione um contato à esquerda para escrever.</p>
                    <div class="wrap">
                        <input type="text" placeholder="Digite sua mensagem…" disabled aria-describedby="orientacao-composer">
                        <button class="submit" type="button" disabled aria-label="Enviar" aria-describedby="orientacao-composer">
                            <i class="fa fa-paper-plane" aria-hidden="true"></i>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</body>

</html>
