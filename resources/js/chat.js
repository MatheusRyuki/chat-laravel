import { obterEcho } from './echo';

const frame = document.getElementById('frame');
const usuarioId = frame?.dataset.userId;
const contatoId = frame?.dataset.contatoId;
const loginUrl = frame?.dataset.loginUrl || '/login';
const fusoApp = frame?.dataset.fuso || 'UTC';
const areaMensagens = document.querySelector('.messages');
const listaMensagens = document.getElementById('lista-mensagens');
const indicadorCarregamento = document.getElementById('conversation-loader');
const avisoSincronizacao = document.getElementById('aviso-sincronizacao');
const anuncioMensagens = document.getElementById('anuncio-mensagens');
const formularioMensagem = document.getElementById('formulario-mensagem');
const campoConteudo = document.querySelector('.message-input input[name="conteudo"]');
const erroEnvio = document.getElementById('erro-envio');
const botaoEnviar = formularioMensagem?.querySelector('button[type="submit"]');
const sidepanel = document.getElementById('sidepanel');
const sidebarToggle = document.getElementById('sidebar-toggle');
const sidebarBackdrop = document.getElementById('sidebar-backdrop');
const areaConteudo = document.querySelector('#frame > .content');
const consultaGaveta = window.matchMedia('(max-width: 735px)');
const LIMIAR_ROLAGEM = 80;
const NOME_CANAL_PRESENCA = 'presenca.chat';
const CHAVE_SESSAO_ENCERRADA = 'chat-sessao-encerrada';
const PREFIXO_RASCUNHO = 'chat-rascunho:';
const ROTULOS_PRESENCA = {
    aguardando: 'Presença a confirmar',
    online: 'Online',
    offline: 'Offline',
    indisponivel: 'Presença indisponível',
};

window.__chatInicioSessao = Date.now();

let presencaConfirmada = false;
let presencaIndisponivel = false;
let envioEmAndamento = false;

function ocultarCarregamento() {
    if (indicadorCarregamento) {
        indicadorCarregamento.hidden = true;
    }

    if (areaMensagens) {
        areaMensagens.setAttribute('aria-busy', 'false');
    }
}

function mostrarCarregamento() {
    if (indicadorCarregamento) {
        indicadorCarregamento.hidden = false;
    }

    if (areaMensagens) {
        areaMensagens.setAttribute('aria-busy', 'true');
    }
}

function navegacaoMesmaAba(event, link) {
    if (event.defaultPrevented || event.button !== 0) {
        return false;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false;
    }

    if (link.target && link.target !== '_self') {
        return false;
    }

    const destino = new URL(link.href, window.location.href);

    return destino.pathname !== window.location.pathname
        || destino.search !== window.location.search;
}

function pertenceAConversaAberta(payload) {
    if (! usuarioId || ! contatoId) {
        return false;
    }

    const participantes = [Number(payload.remetente_id), Number(payload.destinatario_id)];

    return participantes.includes(Number(usuarioId)) && participantes.includes(Number(contatoId));
}

function usuarioProximoDoFim() {
    if (! areaMensagens) {
        return true;
    }

    return areaMensagens.scrollHeight - areaMensagens.scrollTop - areaMensagens.clientHeight < LIMIAR_ROLAGEM;
}

function compararMensagens(a, b) {
    if (a.created_at === b.created_at) {
        return Number(a.id) - Number(b.id);
    }

    return a.created_at < b.created_at ? -1 : 1;
}

function partesNoFuso(data, fuso) {
    const partes = new Intl.DateTimeFormat('en-GB', {
        timeZone: fuso,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(data);

    return Object.fromEntries(partes.filter((parte) => parte.type !== 'literal').map((parte) => [parte.type, parte.value]));
}

function formatarHorarioMensagem(iso) {
    if (! iso) {
        return '';
    }

    const momento = new Date(iso);

    if (Number.isNaN(momento.getTime())) {
        return '';
    }

    const atual = partesNoFuso(new Date(), fusoApp);
    const mensagem = partesNoFuso(momento, fusoApp);
    const hora = `${mensagem.hour}:${mensagem.minute}`;

    if (mensagem.year === atual.year && mensagem.month === atual.month && mensagem.day === atual.day) {
        return hora;
    }

    if (mensagem.year === atual.year) {
        return `${mensagem.day}/${mensagem.month}, ${hora}`;
    }

    return `${mensagem.day}/${mensagem.month}/${mensagem.year}, ${hora}`;
}

function paragrafoSeguro(texto) {
    const paragrafo = document.createElement('p');
    const partes = String(texto).split('\n');

    partes.forEach((parte, indice) => {
        if (indice > 0) {
            paragrafo.appendChild(document.createElement('br'));
        }

        paragrafo.appendChild(document.createTextNode(parte));
    });

    return paragrafo;
}

function nomeDoContatoAberto() {
    return document.querySelector('.contact-profile p')?.textContent?.trim() || 'contato';
}

function anunciarMensagemRecebida(texto) {
    if (! anuncioMensagens) {
        return;
    }

    const trecho = String(texto).replace(/\s+/g, ' ').trim();
    const resumido = trecho.length > 120 ? `${trecho.slice(0, 117)}…` : trecho;

    anuncioMensagens.textContent = `Nova mensagem de ${nomeDoContatoAberto()}: ${resumido}`;
}

function inserirMensagem(payload, opcoes = {}) {
    const anunciar = Boolean(opcoes.anunciar);

    if (! listaMensagens || ! pertenceAConversaAberta(payload) || payload?.id == null) {
        return false;
    }

    if (listaMensagens.querySelector(`[data-mensagem-id="${payload.id}"]`)) {
        return false;
    }

    const pertoDoFim = usuarioProximoDoFim();
    const enviada = Number(payload.remetente_id) === Number(usuarioId);
    const item = document.createElement('li');
    item.className = enviada ? 'replies' : 'sent';
    item.dataset.mensagemId = String(payload.id);
    item.dataset.createdAt = payload.created_at || '';

    const imagem = document.createElement('img');
    imagem.alt = '';
    imagem.src = enviada
        ? (document.getElementById('profile-img')?.src ?? '')
        : (document.querySelector('.contact-profile img')?.src ?? '');

    const corpo = document.createElement('div');
    corpo.className = 'mensagem-corpo';
    corpo.appendChild(paragrafoSeguro(payload.conteudo));

    const horario = formatarHorarioMensagem(payload.created_at);
    if (horario !== '') {
        const tempo = document.createElement('time');
        tempo.className = 'mensagem-horario';
        tempo.dateTime = payload.created_at || '';
        tempo.textContent = horario;
        corpo.appendChild(tempo);
    }

    item.appendChild(imagem);
    item.appendChild(corpo);

    const seguinte = [...listaMensagens.querySelectorAll('li[data-mensagem-id]')].find((existente) => compararMensagens({
        id: existente.dataset.mensagemId,
        created_at: existente.dataset.createdAt,
    }, {
        id: payload.id,
        created_at: payload.created_at || '',
    }) > 0);

    if (seguinte) {
        listaMensagens.insertBefore(item, seguinte);
    } else {
        listaMensagens.appendChild(item);
    }

    document.querySelector('.messages-empty')?.remove();

    if (pertoDoFim && areaMensagens) {
        areaMensagens.scrollTop = areaMensagens.scrollHeight;
    }

    if (anunciar && ! enviada) {
        anunciarMensagemRecebida(payload.conteudo);
    }

    return true;
}

function chaveRascunho(idContato) {
    return `${PREFIXO_RASCUNHO}${usuarioId}:${idContato}`;
}

function lerRascunho(idContato) {
    if (! usuarioId || ! idContato) {
        return null;
    }

    try {
        return sessionStorage.getItem(chaveRascunho(idContato));
    } catch (erro) {
        return null;
    }
}

function gravarRascunho(idContato, texto) {
    if (! usuarioId || ! idContato) {
        return;
    }

    try {
        const chave = chaveRascunho(idContato);

        if (texto) {
            sessionStorage.setItem(chave, texto);
        } else {
            sessionStorage.removeItem(chave);
        }
    } catch (erro) {
        // sessionStorage pode estar indisponível
    }
}

function limparRascunhosDoUsuario() {
    if (! usuarioId) {
        return;
    }

    try {
        const prefixo = `${PREFIXO_RASCUNHO}${usuarioId}:`;
        const chaves = [];

        for (let indice = 0; indice < sessionStorage.length; indice += 1) {
            const chave = sessionStorage.key(indice);

            if (chave && chave.startsWith(prefixo)) {
                chaves.push(chave);
            }
        }

        chaves.forEach((chave) => sessionStorage.removeItem(chave));
    } catch (erro) {
        // sessionStorage pode estar indisponível
    }
}

function restaurarRascunhoInicial() {
    if (! contatoId || ! campoConteudo) {
        return;
    }

    const salvo = lerRascunho(contatoId);

    if (campoConteudo.value !== '') {
        gravarRascunho(contatoId, campoConteudo.value);

        return;
    }

    if (salvo) {
        campoConteudo.value = salvo;
    }
}

function persistirRascunhoAtual() {
    if (! contatoId || ! campoConteudo) {
        return;
    }

    gravarRascunho(contatoId, campoConteudo.value);
}

function limparRascunhoSeCorrespondente(textoEnviado) {
    const salvo = lerRascunho(contatoId);

    if (salvo === textoEnviado) {
        gravarRascunho(contatoId, '');
    }

    if (campoConteudo && campoConteudo.value === textoEnviado) {
        campoConteudo.value = '';
        persistirRascunhoAtual();
    }
}

function tokenCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

function mostrarAvisoSincronizacao(visivel) {
    if (avisoSincronizacao) {
        avisoSincronizacao.hidden = ! visivel;
    }
}

function mostrarErroEnvio(texto) {
    if (! erroEnvio) {
        return;
    }

    erroEnvio.hidden = ! texto;
    erroEnvio.textContent = texto || '';
}

function primeiraMensagemValidacao(corpo) {
    const erros = corpo?.errors;

    if (! erros || typeof erros !== 'object') {
        return 'Não foi possível enviar a mensagem.';
    }

    const primeiro = Object.values(erros).flat()[0];

    return typeof primeiro === 'string' ? primeiro : 'Não foi possível enviar a mensagem.';
}

function reconciliar() {
    if (! contatoId) {
        return;
    }

    const token = tokenCsrf();

    fetch(`/mensagens?contato=${encodeURIComponent(contatoId)}`, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
    })
        .then((resposta) => (resposta.ok ? resposta.json() : Promise.reject(resposta)))
        .then((dados) => {
            (dados.mensagens || []).forEach((mensagem) => inserirMensagem(mensagem, { anunciar: false }));
            mostrarAvisoSincronizacao(false);
        })
        .catch(() => {
            mostrarAvisoSincronizacao(true);
        });
}

function indicadoresPresenca() {
    return document.querySelectorAll('[data-presenca-usuario]');
}

function aplicarEstadoPresenca(elemento, estado) {
    elemento.classList.remove('aguardando', 'online', 'offline', 'indisponivel');
    elemento.classList.add(estado);

    const rotulo = ROTULOS_PRESENCA[estado] || ROTULOS_PRESENCA.aguardando;

    if (elemento.tagName !== 'IMG') {
        elemento.setAttribute('aria-label', rotulo);
    }

    const texto = elemento.querySelector('.presenca-texto');

    if (texto) {
        texto.textContent = rotulo;
    }
}

function marcarTodosPresenca(estado) {
    indicadoresPresenca().forEach((elemento) => aplicarEstadoPresenca(elemento, estado));
}

function substituirMembrosPresenca(membros) {
    presencaConfirmada = true;
    presencaIndisponivel = false;

    const presentes = new Set((membros || []).map((membro) => Number(membro.id)));

    indicadoresPresenca().forEach((elemento) => {
        const id = Number(elemento.dataset.presencaUsuario);

        aplicarEstadoPresenca(elemento, presentes.has(id) ? 'online' : 'offline');
    });
}

function atualizarMembroPresenca(membro, estado) {
    if (! presencaConfirmada || presencaIndisponivel) {
        return;
    }

    const id = Number(membro?.id);

    indicadoresPresenca().forEach((elemento) => {
        if (Number(elemento.dataset.presencaUsuario) === id) {
            aplicarEstadoPresenca(elemento, estado);
        }
    });
}

function marcarPresencaIndisponivel() {
    presencaConfirmada = false;
    presencaIndisponivel = true;
    marcarTodosPresenca('indisponivel');
}

function encerrarConexoesChat() {
    window.__chatSessaoEncerrada = true;

    const echo = window.Echo;

    if (echo) {
        echo.leave(NOME_CANAL_PRESENCA);

        if (usuarioId) {
            echo.leave(`App.Models.User.${usuarioId}`);
        }

        echo.disconnect();
    }

    window.__chatCanalPresenca = null;
    window.__chatCanalPrivado = null;
}

function garantirListenersConexao(echo) {
    if (window.__chatListenersConexao) {
        return;
    }

    window.__chatListenersConexao = true;

    const conexao = echo.connector?.pusher?.connection;

    if (! conexao) {
        return;
    }

    conexao.bind('connected', () => {
        if (! window.__chatSessaoEncerrada) {
            reconciliar();
        }
    });

    conexao.bind('disconnected', marcarPresencaIndisponivel);
    conexao.bind('unavailable', marcarPresencaIndisponivel);
    conexao.bind('failed', marcarPresencaIndisponivel);
}

function inscreverCanalPrivado(echo) {
    if (window.__chatCanalPrivado) {
        return;
    }

    window.__chatCanalPrivado = echo.private(`App.Models.User.${usuarioId}`);

    window.__chatCanalPrivado
        .subscribed(() => {
            console.info(`Canal privado App.Models.User.${usuarioId} assinado.`);
            reconciliar();
        })
        .listen('.diagnostico.pusher', (payload) => {
            console.info('Diagnóstico Pusher recebido:', payload);
        })
        .listen('.mensagem.enviada', (payload) => {
            inserirMensagem(payload, { anunciar: true });
        });
}

function inscreverPresenca(echo) {
    if (window.__chatCanalPresenca || window.__chatSessaoEncerrada) {
        return;
    }

    window.__chatCanalPresenca = echo.join(NOME_CANAL_PRESENCA);

    window.__chatCanalPresenca
        .here((membros) => {
            if (window.__chatSessaoEncerrada) {
                return;
            }

            substituirMembrosPresenca(membros);
        })
        .joining((membro) => atualizarMembroPresenca(membro, 'online'))
        .leaving((membro) => atualizarMembroPresenca(membro, 'offline'))
        .error(() => marcarPresencaIndisponivel());
}

function gavetaAberta() {
    return Boolean(frame?.classList.contains('sidebar-expanded'));
}

function rotuloToggle(aberto) {
    const visivel = sidebarToggle?.querySelector('.sr-only');

    if (visivel) {
        visivel.textContent = aberto ? 'Fechar lista de contatos' : 'Abrir lista de contatos';
    }
}

function elementosFocaveis(raiz) {
    return [...raiz.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])')]
        .filter((elemento) => elemento.getAttribute('tabindex') !== '-1' && ! elemento.hasAttribute('hidden') && elemento.offsetParent !== null);
}

function abrirGaveta() {
    if (! frame || ! consultaGaveta.matches) {
        return;
    }

    frame.classList.add('sidebar-expanded');
    sidebarToggle?.setAttribute('aria-expanded', 'true');

    if (sidebarBackdrop) {
        sidebarBackdrop.hidden = false;
    }

    areaConteudo?.setAttribute('inert', '');
    areaConteudo?.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = 'hidden';
    rotuloToggle(true);
}

function fecharGaveta(opcoes = {}) {
    const devolverFoco = opcoes.devolverFoco !== false;

    frame?.classList.remove('sidebar-expanded');
    sidebarToggle?.setAttribute('aria-expanded', 'false');

    if (sidebarBackdrop) {
        sidebarBackdrop.hidden = true;
    }

    areaConteudo?.removeAttribute('inert');
    areaConteudo?.removeAttribute('aria-hidden');
    document.body.style.overflow = '';
    rotuloToggle(false);

    if (devolverFoco && consultaGaveta.matches) {
        sidebarToggle?.focus();
    }
}

function aoMudarBreakpointGaveta() {
    if (! consultaGaveta.matches) {
        fecharGaveta({ devolverFoco: false });
    }
}

function aoTeclaGaveta(event) {
    if (! gavetaAberta() || ! consultaGaveta.matches) {
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        fecharGaveta();

        return;
    }

    if (event.key !== 'Tab' || ! sidepanel) {
        return;
    }

    const focaveis = elementosFocaveis(sidepanel);

    if (focaveis.length === 0) {
        return;
    }

    const primeiro = focaveis[0];
    const ultimo = focaveis[focaveis.length - 1];

    if (event.shiftKey && document.activeElement === primeiro) {
        event.preventDefault();
        ultimo.focus();
    } else if (! event.shiftKey && document.activeElement === ultimo) {
        event.preventDefault();
        primeiro.focus();
    }
}

function enviarMensagemAssincrona(event) {
    if (! formularioMensagem || typeof window.fetch !== 'function') {
        return;
    }

    event.preventDefault();

    if (envioEmAndamento || ! campoConteudo) {
        return;
    }

    const texto = campoConteudo.value;
    const token = tokenCsrf();
    const destinatario = formularioMensagem.querySelector('input[name="destinatario_id"]')?.value;

    envioEmAndamento = true;
    botaoEnviar?.setAttribute('aria-busy', 'true');
    if (botaoEnviar) {
        botaoEnviar.disabled = true;
    }
    mostrarErroEnvio('');

    fetch(formularioMensagem.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify({
            destinatario_id: Number(destinatario),
            conteudo: texto,
        }),
    })
        .then(async (resposta) => {
            if (resposta.status === 201) {
                const dados = await resposta.json();
                inserirMensagem(dados.mensagem, { anunciar: false });
                limparRascunhoSeCorrespondente(texto);

                return;
            }

            if (resposta.status === 422) {
                const corpo = await resposta.json().catch(() => null);
                mostrarErroEnvio(primeiraMensagemValidacao(corpo));

                return;
            }

            if (resposta.status === 401 || resposta.status === 419) {
                mostrarErroEnvio('Sessão expirada. Entre novamente para enviar. O texto foi preservado.');

                return;
            }

            mostrarErroEnvio('Não foi possível confirmar o envio. Verifique a conversa antes de reenviar.');
        })
        .catch(() => {
            mostrarErroEnvio('Falha de rede. A mensagem não foi enviada.');
        })
        .finally(() => {
            envioEmAndamento = false;
            botaoEnviar?.removeAttribute('aria-busy');
            if (botaoEnviar) {
                botaoEnviar.disabled = false;
            }
            campoConteudo?.focus();
        });
}

function tratarSinalLogout(valor) {
    const marcado = Number(valor);

    if (! marcado || marcado < window.__chatInicioSessao) {
        return;
    }

    limparRascunhosDoUsuario();
    encerrarConexoesChat();
    window.location.assign(loginUrl);
}

document.getElementById('contacts')?.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');

    if (! link || ! navegacaoMesmaAba(event, link)) {
        return;
    }

    persistirRascunhoAtual();
    mostrarCarregamento();
});

window.addEventListener('pageshow', () => {
    ocultarCarregamento();
});

document.getElementById('formulario-sair')?.addEventListener('submit', () => {
    limparRascunhosDoUsuario();

    try {
        localStorage.setItem(CHAVE_SESSAO_ENCERRADA, String(Date.now()));
    } catch (erro) {
        // o armazenamento local pode estar indisponível
    }

    encerrarConexoesChat();
});

window.addEventListener('storage', (evento) => {
    if (evento.key !== CHAVE_SESSAO_ENCERRADA || ! evento.newValue) {
        return;
    }

    tratarSinalLogout(evento.newValue);
});

campoConteudo?.addEventListener('input', persistirRascunhoAtual);

formularioMensagem?.addEventListener('submit', enviarMensagemAssincrona);

sidebarToggle?.addEventListener('click', () => {
    if (! consultaGaveta.matches) {
        return;
    }

    if (gavetaAberta()) {
        fecharGaveta();
    } else {
        abrirGaveta();
    }
});

sidebarBackdrop?.addEventListener('click', () => fecharGaveta());
document.addEventListener('keydown', aoTeclaGaveta);
window.addEventListener('resize', aoMudarBreakpointGaveta);

if (typeof consultaGaveta.addEventListener === 'function') {
    consultaGaveta.addEventListener('change', aoMudarBreakpointGaveta);
} else if (typeof consultaGaveta.addListener === 'function') {
    consultaGaveta.addListener(aoMudarBreakpointGaveta);
}

restaurarRascunhoInicial();

if (areaMensagens) {
    areaMensagens.scrollTop = areaMensagens.scrollHeight;
}

if (usuarioId && ! window.__chatSessaoEncerrada) {
    const echo = obterEcho();

    if (! echo) {
        marcarPresencaIndisponivel();
    } else {
        garantirListenersConexao(echo);
        inscreverCanalPrivado(echo);
        inscreverPresenca(echo);
    }
}
