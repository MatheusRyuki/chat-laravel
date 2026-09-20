<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $selecionado ? $selecionado->name.' · '.config('app.name') : ($conversa?->nome ? $conversa->nome.' · '.config('app.name') : config('app.name')) }}</title>
    <x-favicon />
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
    @endphp

    <div id="frame"
        data-user-id="{{ $user->id }}"
        data-fuso="{{ config('app.timezone') }}"
        data-login-url="{{ route('login') }}"
        data-prefixo-canal="{{ prefixo_canal_broadcast() }}"
        data-expira-digitacao="{{ (int) config('chat.digitacao_expira_em') }}"
        @if ($selecionado) data-contato-id="{{ $selecionado->id }}" @endif
        @if ($conversa) data-conversa-id="{{ $conversa->id }}" data-versao-conversa="{{ $conversa->versao }}" @endif
        @if ($eGrupo) data-grupo-id="{{ $conversa->id }}" @endif
        @if ($bloqueada) data-bloqueada="1" @endif>
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
                <a id="abrir-criar-grupo" class="botao-novo-grupo" href="{{ request()->fullUrlWithQuery(['criar_grupo' => 1]) }}" aria-haspopup="dialog" aria-controls="modal-criar-grupo">Novo grupo</a>
                <div class="acoes-conta">
                    <a class="acao-secundaria link-conta" href="{{ route('profile.edit') }}">Conta</a>
                    <form id="formulario-sair" method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="acao-secundaria">Sair</button>
                    </form>
                </div>
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
                    @if ($conversa->usuarioECriador($user))
                        <a id="abrir-membros-grupo" class="botao-gerenciar-membros" href="{{ request()->fullUrlWithQuery(['gerenciar_membros' => 1]) }}" aria-haspopup="dialog" aria-controls="modal-membros-grupo">Gerenciar membros</a>
                    @endif
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
                                    @if (filled(trim((string) $mensagem->conteudo)))
                                        <p>{!! \App\Support\FormatadorMensagem::paraHtml($mensagem->conteudo) !!}</p>
                                    @endif
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
        </div>
    </div>
    @php
        $abrirModalGrupo = request()->boolean('criar_grupo')
            || $errors->has('nome')
            || $errors->has('membros')
            || collect($errors->keys())->contains(fn (string $chave): bool => str_starts_with($chave, 'membros.'));
        $urlFecharModalGrupo = request()->fullUrlWithoutQuery(['criar_grupo']);
        $idsMembrosAntigos = collect(old('membros', []))->map(fn ($id): int => (int) $id);
        $podeGerenciarMembros = $eGrupo && $conversa->usuarioECriador($user);
        $abrirModalMembros = $podeGerenciarMembros
            && (request()->boolean('gerenciar_membros') || $errors->has('user_id'));
        $urlFecharModalMembros = $conversa
            ? route('dashboard', ['grupo' => $conversa->id])
            : request()->fullUrlWithoutQuery(['gerenciar_membros']);
        $quantidadeParticipantes = $conversa?->participantesAtivos->count() ?? 0;
    @endphp
    <dialog id="modal-criar-grupo" class="modal-criar-grupo" aria-labelledby="titulo-criar-grupo" aria-describedby="ajuda-grupo" @if ($abrirModalGrupo) open @endif>
        <form method="POST" action="{{ route('grupos.store') }}" class="formulario-grupo" id="formulario-criar-grupo">
            @csrf
            <div class="modal-criar-grupo-cabecalho">
                <h2 id="titulo-criar-grupo">Criar grupo</h2>
                <a href="{{ $urlFecharModalGrupo }}" class="modal-criar-grupo-fechar" data-fechar-modal-grupo data-fechar-dialogo aria-label="Fechar">×</a>
            </div>
            <div class="modal-criar-grupo-corpo">
                <div class="modal-criar-grupo-campo">
                    <label for="nome-grupo">Nome do grupo</label>
                    <input id="nome-grupo" type="text" name="nome" maxlength="80" required value="{{ old('nome') }}" aria-describedby="ajuda-grupo{{ $errors->has('nome') ? ' erro-nome-grupo' : '' }}" @if ($errors->has('nome')) aria-invalid="true" @endif>
                    @error('nome')
                        <p id="erro-nome-grupo" class="erro-modal-grupo">{{ $message }}</p>
                    @enderror
                </div>
                <fieldset class="modal-criar-grupo-participantes">
                    <legend>Participantes</legend>
                    <ul class="lista-participantes-grupo">
                        @foreach ($usuariosParaGrupo as $candidato)
                            <li>
                                <label class="participante-grupo">
                                    <img src="{{ avatar_data_uri($candidato->name) }}" alt="">
                                    <span>{{ $candidato->name }}</span>
                                    <input type="checkbox" name="membros[]" value="{{ $candidato->id }}" @checked($idsMembrosAntigos->contains($candidato->id))>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    @error('membros')
                        <p id="erro-membros-grupo" class="erro-modal-grupo">{{ $message }}</p>
                    @enderror
                </fieldset>
                <p id="ajuda-grupo" class="ajuda-grupo">Novos membros podem consultar o histórico do grupo.</p>
            </div>
            <div class="modal-criar-grupo-rodape">
                <a href="{{ $urlFecharModalGrupo }}" class="modal-criar-grupo-cancelar" data-fechar-modal-grupo data-fechar-dialogo>Cancelar</a>
                <button type="submit" class="modal-criar-grupo-enviar">Criar grupo</button>
            </div>
        </form>
    </dialog>
    @if ($podeGerenciarMembros)
        <dialog id="modal-membros-grupo" class="modal-criar-grupo" aria-labelledby="titulo-membros-grupo" aria-describedby="ajuda-membros-grupo" @if ($abrirModalMembros) open @endif>
            <div class="formulario-grupo">
                <div class="modal-criar-grupo-cabecalho">
                    <h2 id="titulo-membros-grupo" tabindex="-1">Participantes do grupo</h2>
                    <a href="{{ $urlFecharModalMembros }}" class="modal-criar-grupo-fechar" data-fechar-dialogo aria-label="Fechar">×</a>
                </div>
                <div class="modal-criar-grupo-corpo">
                    <p class="subtitulo-membros-grupo">{{ $conversa->nome }} · {{ $quantidadeParticipantes }} {{ $quantidadeParticipantes === 1 ? 'participante' : 'participantes' }}</p>
                    <ul class="lista-membros-grupo">
                        @foreach ($conversa->participantesAtivos as $participante)
                            <li class="membro-grupo-linha">
                                <img src="{{ avatar_data_uri($participante->user?->name ?? '') }}" alt="">
                                <span class="membro-grupo-nome">{{ $participante->user?->name }}</span>
                                @if ($participante->user_id !== $user->id)
                                    <form method="POST" action="{{ route('grupos.membros.destroy', [$conversa, $participante->user]) }}" class="formulario-remover-membro">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit">Remover</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <section class="secao-adicionar-membro" aria-labelledby="titulo-adicionar-membro">
                        <h3 id="titulo-adicionar-membro">Adicionar participante</h3>
                        <form method="POST" action="{{ route('grupos.membros.store', $conversa) }}" class="formulario-adicionar-membro">
                            @csrf
                            <label class="sr-only" for="novo-membro">Adicionar participante</label>
                            <div class="adicionar-membro-controles">
                                <select id="novo-membro" name="user_id" required @if ($errors->has('user_id')) aria-invalid="true" aria-describedby="erro-novo-membro" @endif>
                                    <option value="">Adicionar participante</option>
                                    @foreach ($usuariosParaGrupo->whereNotIn('id', $conversa->participantesAtivos->pluck('user_id')) as $candidato)
                                        <option value="{{ $candidato->id }}" @selected((int) old('user_id') === (int) $candidato->id)>{{ $candidato->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="modal-criar-grupo-enviar">Adicionar</button>
                            </div>
                            @error('user_id')
                                <p id="erro-novo-membro" class="erro-modal-grupo">{{ $message }}</p>
                            @enderror
                        </form>
                    </section>
                    <p id="ajuda-membros-grupo" class="ajuda-grupo">Novos membros podem consultar o histórico do grupo. Membros removidos perdem o acesso ao histórico e aos eventos futuros.</p>
                </div>
                <div class="modal-criar-grupo-rodape">
                    <a href="{{ $urlFecharModalMembros }}" class="modal-criar-grupo-cancelar" data-fechar-dialogo>Fechar</a>
                </div>
            </div>
        </dialog>
    @endif
</body>

</html>
