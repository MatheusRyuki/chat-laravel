<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
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

    <div id="frame" data-user-id="{{ $user->id }}"@if ($selecionado) data-contato-id="{{ $selecionado->id }}"@endif>
        <div id="sidepanel">
            <div id="profile">
                <div class="wrap">
                    <img id="profile-img" src="{{ $userAvatar }}" class="online" alt="{{ $user->name }}" />
                    <p>{{ $user->name }}</p>
                    <i class="fa fa-chevron-down expand-button" aria-hidden="true"></i>
                    <div id="status-options">
                        <ul>
                            <li id="status-online" class="active"><span class="status-circle"></span>
                                <p>Online</p>
                            </li>
                            <li id="status-away"><span class="status-circle"></span>
                                <p>Ausente</p>
                            </li>
                            <li id="status-busy"><span class="status-circle"></span>
                                <p>Ocupado</p>
                            </li>
                            <li id="status-offline"><span class="status-circle"></span>
                                <p>Offline</p>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <button type="button" id="sidebar-toggle" aria-expanded="false" aria-controls="contacts">
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
                                <a href="{{ route('dashboard', ['contato' => $contato->id]) }}">
                                    <div class="wrap">
                                        <span class="contact-status aguardando" data-presenca-usuario="{{ $contato->id }}" role="status" aria-label="Presença a confirmar"></span>
                                        <img src="{{ avatar_data_uri($contato->name) }}" alt="{{ $contato->name }}" />
                                        <div class="meta">
                                            <p class="name">{{ $contato->name }}</p>
                                            <p class="preview">{{ $contato->email }}</p>
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
                @if ($selecionado && $mensagens->isEmpty())
                    <p class="messages-empty">Nenhuma mensagem nesta conversa.</p>
                @endif
                <ul id="lista-mensagens">
                    @foreach ($mensagens as $mensagem)
                        @php
                            $enviada = $mensagem->remetente_id === $user->id;
                        @endphp
                        <li class="{{ $enviada ? 'replies' : 'sent' }}" data-mensagem-id="{{ $mensagem->id }}" data-created-at="{{ $mensagem->created_at?->toIso8601String() }}">
                            <img src="{{ $enviada ? $userAvatar : $selecionadoAvatar }}" alt="">
                            <p>{!! nl2br(e($mensagem->conteudo)) !!}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="message-input">
                @if ($selecionado)
                    <form method="POST" action="{{ route('mensagens.store') }}">
                        @csrf
                        <input type="hidden" name="destinatario_id" value="{{ $selecionado->id }}">
                        <div class="wrap">
                            <input type="text" name="conteudo" placeholder="Digite sua mensagem…" maxlength="{{ \App\Models\Mensagem::TAMANHO_MAXIMO }}" value="{{ old('conteudo') }}" required>
                            <button class="submit" type="submit">
                                <i class="fa fa-paper-plane" aria-hidden="true"></i>
                                <span class="sr-only">Enviar</span>
                            </button>
                        </div>
                        @error('conteudo')
                            <p class="mensagem-erro">{{ $message }}</p>
                        @enderror
                        @error('destinatario_id')
                            <p class="mensagem-erro">{{ $message }}</p>
                        @enderror
                    </form>
                @else
                    <div class="wrap">
                        <input type="text" placeholder="Digite sua mensagem…" disabled />
                        <button class="submit" type="button" disabled>
                            <i class="fa fa-paper-plane" aria-hidden="true"></i>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <script>
        (function () {
            var frame = document.getElementById('frame');
            var toggle = document.getElementById('sidebar-toggle');
            if (!frame || !toggle) {
                return;
            }

            toggle.addEventListener('click', function () {
                var expanded = frame.classList.toggle('sidebar-expanded');
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                toggle.querySelector('i').className = expanded ? 'fa fa-times' : 'fa fa-bars';
                toggle.querySelector('.sr-only').textContent = expanded
                    ? 'Recolher lista de contatos'
                    : 'Abrir lista de contatos';
            });
        })();
    </script>
</body>

</html>
