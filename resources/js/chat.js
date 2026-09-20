import { obterEcho } from './echo';

const frame = document.getElementById('frame');
const usuarioId = frame?.dataset.userId;
const contatoId = frame?.dataset.contatoId;
const conversaId = frame?.dataset.conversaId;
const grupoId = frame?.dataset.grupoId;
const loginUrl = frame?.dataset.loginUrl || '/login';
const fusoApp = frame?.dataset.fuso || 'UTC';
const prefixoCanal = frame?.dataset.prefixoCanal || '';
const expiraDigitacaoMs = (Number(frame?.dataset.expiraDigitacao) || 3) * 1000;
const areaMensagens = document.querySelector('.messages');
const listaMensagens = document.getElementById('lista-mensagens');
const listaContatos = document.querySelector('#contacts ul');
const indicadorCarregamento = document.getElementById('conversation-loader');
const avisoSincronizacao = document.getElementById('aviso-sincronizacao');
const anuncioMensagens = document.getElementById('anuncio-mensagens');
const formularioMensagem = document.getElementById('formulario-mensagem');
const campoConteudo = document.querySelector('.message-input [name="conteudo"]');
const campoAnexo = document.getElementById('campo-anexo');
const previaAnexo = document.getElementById('pre-visualizacao-anexo');
const erroEnvio = document.getElementById('erro-envio');
const botaoEnviar = formularioMensagem?.querySelector('button[type="submit"]');
const botaoAnteriores = document.getElementById('carregar-anteriores');
const indicadorDigitacao = document.getElementById('indicador-digitacao');
const sidepanel = document.getElementById('sidepanel');
const sidebarToggle = document.getElementById('sidebar-toggle');
const sidebarBackdrop = document.getElementById('sidebar-backdrop');
const areaConteudo = document.querySelector('#frame > .content');
const consultaGaveta = window.matchMedia('(max-width: 735px)');
const LIMIAR_ROLAGEM = 80;
const TEXTO_CONFIRMAR_REMOCAO = 'Esta mensagem será removida para os participantes da conversa.';
const NOME_CANAL_PRESENCA = `${prefixoCanal}presenca.chat`;
const CHAVE_SESSAO_ENCERRADA = 'chat-sessao-encerrada';
const PREFIXO_RASCUNHO = 'chat-rascunho:';
const CHAVE_LEITURA = 'chat-leitura:';
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
let compondoIme = false;
let versaoConversa = Number(frame?.dataset.versaoConversa || 0);
let carregandoAnteriores = false;
let leituraEmAndamento = false;
let temporizadorDigitacao = null;
let digitandoEnviado = false;
const digitandoPorUsuario = new Map();
const revogadas = new Set();
const leituraPorConversa = new Map();

window.__chatDiagnostico = {
    prefixoCanal,
    usuarioId: usuarioId ? Number(usuarioId) : null,
    conversaId: conversaId ? Number(conversaId) : null,
    canal: `${prefixoCanal}App.Models.User.${usuarioId || ''}`,
    estado: 'inicial',
    assinado: false,
    eventosPusher: [],
    reconciliacoes: [],
};

function registrarEventoPusher(nome, payload) {
    window.__chatDiagnostico.eventosPusher.push({
        nome,
        t: Date.now(),
        id: payload?.id ?? null,
        conversa_id: payload?.conversa_id ?? payload?.conversaId ?? null,
        conteudo: payload?.conteudo ?? null,
        versao: payload?.versao ?? null,
        removida: Boolean(payload?.removida),
        editada: Boolean(payload?.editada),
        usuario_id: payload?.usuario_id ?? null,
        acao: payload?.acao ?? null,
        digitando: payload?.digitando,
        nome_participante: payload?.nome ?? null,
    });
}

try {
    window.__chatCanalAbas = new BroadcastChannel('chat-estado');
} catch (erro) {
    window.__chatCanalAbas = null;
}

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

function conversaAindaAutorizada(id) {
    if (! id) {
        return true;
    }

    return ! revogadas.has(Number(id));
}

function pertenceAConversaAberta(payload) {
    if (! usuarioId || payload?.id == null) {
        return false;
    }

    if (payload.conversa_id && conversaId) {
        return Number(payload.conversa_id) === Number(conversaId)
            && conversaAindaAutorizada(payload.conversa_id);
    }

    if (! contatoId) {
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

function tecladoVirtualMovel() {
    return consultaGaveta.matches
        || (window.matchMedia('(pointer: coarse)').matches && 'ontouchstart' in window);
}

function criarConteudoComLinks(texto) {
    const paragrafo = document.createElement('p');
    const partes = String(texto).split('\n');

    partes.forEach((parte, indice) => {
        if (indice > 0) {
            paragrafo.appendChild(document.createElement('br'));
        }

        const regex = /(https?:\/\/[^\s<]+)/gi;
        let cursor = 0;
        let match = regex.exec(parte);

        while (match) {
            if (match.index > cursor) {
                paragrafo.appendChild(document.createTextNode(parte.slice(cursor, match.index)));
            }

            const bruto = match[1];
            const url = bruto.replace(/[.,;:!?)]+$/, '');
            const resto = bruto.slice(url.length);

            if (/^https?:\/\//i.test(url)) {
                const link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = url;
                paragrafo.appendChild(link);
            } else {
                paragrafo.appendChild(document.createTextNode(bruto));
            }

            if (resto) {
                paragrafo.appendChild(document.createTextNode(resto));
            }

            cursor = match.index + bruto.length;
            match = regex.exec(parte);
        }

        if (cursor < parte.length) {
            paragrafo.appendChild(document.createTextNode(parte.slice(cursor)));
        }
    });

    return paragrafo;
}

function previaDePayload(payload) {
    if (payload.removida) {
        return 'Mensagem removida';
    }

    const texto = String(payload.conteudo || '').trim();

    if (payload.anexo_url && texto === '') {
        return 'Imagem';
    }

    if (payload.anexo_url) {
        return `Imagem · ${texto.length > 60 ? `${texto.slice(0, 57)}…` : texto}`;
    }

    if (texto === '') {
        return 'Nenhuma mensagem ainda';
    }

    return texto.length > 80 ? `${texto.slice(0, 77)}…` : texto;
}

function nomeDoContatoAberto() {
    return document.querySelector('.contact-profile .cabecalho-conversa p, .contact-profile p')?.textContent?.trim() || 'contato';
}

function anunciarMensagemRecebida(texto, origem) {
    if (! anuncioMensagens) {
        return;
    }

    const trecho = String(texto).replace(/\s+/g, ' ').trim() || 'imagem';
    const resumido = trecho.length > 120 ? `${trecho.slice(0, 117)}…` : trecho;

    anuncioMensagens.textContent = `Nova mensagem de ${origem}: ${resumido}`;
}

function ultimoIdApresentado() {
    const itens = [...(listaMensagens?.querySelectorAll('li[data-mensagem-id]') || [])];

    return itens.reduce((maior, item) => Math.max(maior, Number(item.dataset.mensagemId) || 0), 0);
}

function abaVisivel() {
    return document.visibilityState === 'visible';
}

function tokenCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

function cabecalhosJson(extra = {}) {
    const token = tokenCsrf();

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        ...extra,
    };
}

function localizarItemLista(payload) {
    if (payload.conversa_id) {
        const porConversa = listaContatos?.querySelector(`li[data-conversa-id="${payload.conversa_id}"]`);

        if (porConversa) {
            return porConversa;
        }
    }

    const outro = Number(payload.remetente_id) === Number(usuarioId)
        ? payload.destinatario_id
        : payload.remetente_id;

    if (! outro) {
        return null;
    }

    return listaContatos?.querySelector(`li[data-contato-id="${outro}"]`) || null;
}

function definirBadge(item, quantidade) {
    const badge = item.querySelector('.nao-lidas');

    if (! badge) {
        return;
    }

    const valor = Math.max(0, Number(quantidade) || 0);
    badge.textContent = String(valor);
    badge.hidden = valor === 0;
    badge.setAttribute('aria-label', `${valor} não lidas`);
}

function atualizarLista(payload, opcoes = {}) {
    const item = localizarItemLista(payload);

    if (! item || ! listaContatos) {
        return;
    }

    if (payload.conversa_id) {
        item.dataset.conversaId = String(payload.conversa_id);
    }

    if (payload.versao && Number(payload.versao) >= Number(item.dataset.versao || 0)) {
        item.dataset.versao = String(payload.versao);
    }

    const previa = item.querySelector('.previa');

    if (previa && (opcoes.forcarPrevia || ! payload.editada || payload.removida || Number(payload.id) === Number(item.dataset.ultimaId))) {
        previa.textContent = previaDePayload(payload);
    }

    if (! payload.editada && ! payload.removida) {
        item.dataset.ultimaId = String(payload.id);
        listaContatos.prepend(item);
    } else if (payload.removida && Number(payload.id) === Number(item.dataset.ultimaId)) {
        previa.textContent = previaDePayload(payload);
    }

    const aberta = pertenceAConversaAberta(payload);
    const propria = Number(payload.remetente_id) === Number(usuarioId);

    if (! propria && ! payload.removida && ! payload.editada && (! aberta || ! abaVisivel())) {
        const atual = Number(item.querySelector('.nao-lidas')?.textContent || 0);
        definirBadge(item, atual + 1);
    }

    if (payload.removida && ! propria) {
        const atual = Number(item.querySelector('.nao-lidas')?.textContent || 0);
        definirBadge(item, Math.max(0, atual - 1));
    }
}

function aplicarLeituraLocal(conversaAlvo, ateId) {
    const id = Number(conversaAlvo);
    const marcador = Number(ateId) || 0;
    const atual = leituraPorConversa.get(id) || 0;

    if (! id || marcador <= atual) {
        return;
    }

    leituraPorConversa.set(id, marcador);

    const item = listaContatos?.querySelector(`li[data-conversa-id="${id}"]`);

    if (item) {
        definirBadge(item, 0);
    }
}

function publicarLeituraEntreAbas(conversaAlvo, ateId) {
    const dados = { conversa_id: Number(conversaAlvo), ate_id: Number(ateId), origem: window.__chatInicioSessao };

    try {
        localStorage.setItem(`${CHAVE_LEITURA}${usuarioId}`, JSON.stringify(dados));
    } catch (erro) {
        // armazenamento pode estar indisponível
    }

    window.__chatCanalAbas?.postMessage(dados);
}

function marcarLeitura() {
    if (! conversaId || ! abaVisivel() || leituraEmAndamento || frame?.dataset.bloqueada === '1') {
        return;
    }

    const ateId = ultimoIdApresentado();

    if (! ateId) {
        return;
    }

    leituraEmAndamento = true;

    fetch('/leituras', {
        method: 'POST',
        credentials: 'same-origin',
        headers: cabecalhosJson({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({
            conversa_id: Number(conversaId),
            ate_id: ateId,
        }),
    })
        .then((resposta) => (resposta.ok ? resposta.json() : Promise.reject(resposta)))
        .then(() => {
            aplicarLeituraLocal(conversaId, ateId);
            publicarLeituraEntreAbas(conversaId, ateId);
        })
        .catch(() => {
            // a leitura será tentada de novo na próxima apresentação
        })
        .finally(() => {
            leituraEmAndamento = false;
        });
}

function avatarPara(payload) {
    const enviada = Number(payload.remetente_id) === Number(usuarioId);

    if (enviada) {
        return document.getElementById('profile-img')?.src ?? '';
    }

    return document.querySelector('.contact-profile img')?.src ?? '';
}

function montarAcoes(payload, corpo) {
    if (Number(payload.remetente_id) !== Number(usuarioId) || payload.removida || frame?.dataset.bloqueada === '1') {
        return;
    }

    const acoes = document.createElement('div');
    acoes.className = 'acoes-mensagem';

    const editar = document.createElement('button');
    editar.type = 'button';
    editar.className = 'editar-mensagem';
    editar.dataset.mensagemId = String(payload.id);
    editar.textContent = 'Editar';

    const remover = document.createElement('button');
    remover.type = 'button';
    remover.className = 'remover-mensagem';
    remover.dataset.mensagemId = String(payload.id);
    remover.textContent = 'Remover';

    acoes.appendChild(editar);
    acoes.appendChild(remover);
    corpo.appendChild(acoes);
}

function preencherCorpo(item, payload) {
    const corpo = item.querySelector('.mensagem-corpo') || document.createElement('div');
    corpo.className = 'mensagem-corpo';
    corpo.replaceChildren();

    if (grupoId && payload.remetente_nome) {
        const nome = document.createElement('span');
        nome.className = 'remetente-nome';
        nome.textContent = payload.remetente_nome;
        corpo.appendChild(nome);
    }

    if (payload.removida) {
        const removida = document.createElement('p');
        removida.className = 'mensagem-removida';
        removida.textContent = 'Mensagem removida';
        corpo.appendChild(removida);
        item.classList.add('removida');
    } else {
        const texto = String(payload.conteudo || '');

        if (texto.trim() !== '') {
            corpo.appendChild(criarConteudoComLinks(texto));
        }

        if (payload.anexo_url) {
            const link = document.createElement('a');
            link.className = 'anexo-mensagem';
            link.href = payload.anexo_url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            const imagem = document.createElement('img');
            imagem.src = payload.anexo_url;
            imagem.alt = 'Imagem enviada';
            link.appendChild(imagem);
            corpo.appendChild(link);
        }

        if (payload.editada) {
            const editada = document.createElement('span');
            editada.className = 'mensagem-editada';
            editada.textContent = 'Editada';
            corpo.appendChild(editada);
        }
    }

    const horario = formatarHorarioMensagem(payload.created_at);

    if (horario !== '') {
        const tempo = document.createElement('time');
        tempo.className = 'mensagem-horario';
        tempo.dateTime = payload.created_at || '';
        tempo.textContent = horario;
        corpo.appendChild(tempo);
    }

    if (! payload.removida) {
        montarAcoes(payload, corpo);
    }

    if (! item.querySelector('.mensagem-corpo')) {
        item.appendChild(corpo);
    }
}

function aplicarMensagem(item, payload) {
    const versaoAtual = Number(item.dataset.versao || 0);
    const versaoNova = Number(payload.versao || 0);

    if (versaoNova && versaoNova < versaoAtual) {
        return false;
    }

    item.dataset.versao = String(versaoNova || versaoAtual);
    item.dataset.createdAt = payload.created_at || item.dataset.createdAt || '';
    item.dataset.remetenteId = String(payload.remetente_id);
    preencherCorpo(item, payload);

    return true;
}

function inserirMensagem(payload, opcoes = {}) {
    const anunciar = Boolean(opcoes.anunciar);
    const prepend = Boolean(opcoes.prepend);

    if (! listaMensagens || ! pertenceAConversaAberta(payload) || payload?.id == null) {
        return false;
    }

    const existente = listaMensagens.querySelector(`[data-mensagem-id="${payload.id}"]`);

    if (existente) {
        return aplicarMensagem(existente, payload);
    }

    const pertoDoFim = usuarioProximoDoFim();
    const alturaAntes = areaMensagens?.scrollHeight || 0;
    const enviada = Number(payload.remetente_id) === Number(usuarioId);
    const item = document.createElement('li');
    item.className = enviada ? 'replies' : 'sent';
    item.dataset.mensagemId = String(payload.id);

    const imagem = document.createElement('img');
    imagem.alt = '';
    imagem.src = avatarPara(payload);
    item.appendChild(imagem);
    aplicarMensagem(item, payload);

    const seguinte = [...listaMensagens.querySelectorAll('li[data-mensagem-id]')].find((existenteLi) => compararMensagens({
        id: existenteLi.dataset.mensagemId,
        created_at: existenteLi.dataset.createdAt,
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

    if (prepend && areaMensagens) {
        areaMensagens.scrollTop += areaMensagens.scrollHeight - alturaAntes;
    } else if (pertoDoFim && areaMensagens) {
        areaMensagens.scrollTop = areaMensagens.scrollHeight;
    }

    if (anunciar && ! enviada) {
        anunciarMensagemRecebida(payload.conteudo || 'imagem', payload.remetente_nome || nomeDoContatoAberto());
    }

    if (payload.versao && Number(payload.versao) > versaoConversa) {
        versaoConversa = Number(payload.versao);
    }

    return true;
}

function tratarEventoMensagem(payload, opcoes = {}) {
    if (payload?.conversa_id && ! conversaAindaAutorizada(payload.conversa_id)) {
        return;
    }

    const aberta = pertenceAConversaAberta(payload);
    atualizarLista(payload, { forcarPrevia: ! payload.editada });

    if (aberta) {
        inserirMensagem(payload, { anunciar: Boolean(opcoes.anunciar) && Number(payload.remetente_id) !== Number(usuarioId) });

        if (abaVisivel()) {
            marcarLeitura();
        }
    } else if (opcoes.anunciar && Number(payload.remetente_id) !== Number(usuarioId) && ! payload.editada && ! payload.removida) {
        const item = localizarItemLista(payload);
        const nome = item?.querySelector('.name')?.childNodes[0]?.textContent?.trim() || 'contato';
        anunciarMensagemRecebida(payload.conteudo || 'imagem', nome);
    }
}

function chaveRascunhoAtual() {
    if (grupoId) {
        return `${PREFIXO_RASCUNHO}${usuarioId}:grupo:${grupoId}`;
    }

    if (contatoId) {
        return `${PREFIXO_RASCUNHO}${usuarioId}:${contatoId}`;
    }

    return null;
}

function lerRascunho() {
    const chave = chaveRascunhoAtual();

    if (! chave) {
        return null;
    }

    try {
        return sessionStorage.getItem(chave);
    } catch (erro) {
        return null;
    }
}

function gravarRascunho(texto) {
    const chave = chaveRascunhoAtual();

    if (! chave) {
        return;
    }

    try {
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
    if (! campoConteudo) {
        return;
    }

    const salvo = lerRascunho();

    if (campoConteudo.value !== '') {
        gravarRascunho(campoConteudo.value);

        return;
    }

    if (salvo) {
        campoConteudo.value = salvo;
    }

    ajustarAlturaComposer();
}

function persistirRascunhoAtual() {
    if (! campoConteudo) {
        return;
    }

    gravarRascunho(campoConteudo.value);
}

function limparRascunhoSeCorrespondente(textoEnviado) {
    const salvo = lerRascunho();

    if (salvo === textoEnviado) {
        gravarRascunho('');
    }

    if (campoConteudo && campoConteudo.value === textoEnviado) {
        campoConteudo.value = '';
        persistirRascunhoAtual();
        ajustarAlturaComposer();
    }
}

function ajustarAlturaComposer() {
    if (! campoConteudo || campoConteudo.tagName !== 'TEXTAREA') {
        return;
    }

    campoConteudo.style.height = 'auto';
    campoConteudo.style.height = `${Math.min(campoConteudo.scrollHeight, 120)}px`;
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

function urlHistorico(extra = {}) {
    const params = new URLSearchParams(extra);

    if (conversaId) {
        params.set('conversa', conversaId);
    } else if (contatoId) {
        params.set('contato', contatoId);
    }

    return `/mensagens?${params.toString()}`;
}

function reconciliar(origem = 'desconhecida') {
    if (! contatoId && ! conversaId) {
        return;
    }

    window.__chatDiagnostico.reconciliacoes.push({
        origem,
        t: Date.now(),
        versao: versaoConversa,
    });

    const params = { versao: String(versaoConversa) };

    fetch(urlHistorico(params), {
        credentials: 'same-origin',
        headers: cabecalhosJson(),
    })
        .then((resposta) => {
            if (resposta.status === 403) {
                perderAcessoGrupo(conversaId);

                return Promise.reject(resposta);
            }

            return resposta.ok ? resposta.json() : Promise.reject(resposta);
        })
        .then((dados) => {
            (dados.mensagens || []).forEach((mensagem) => tratarEventoMensagem(mensagem, { anunciar: false }));

            if (dados.versao) {
                versaoConversa = Math.max(versaoConversa, Number(dados.versao));
            }

            mostrarAvisoSincronizacao(false);

            if (abaVisivel()) {
                marcarLeitura();
            }
        })
        .catch(() => {
            mostrarAvisoSincronizacao(true);
        });
}

function carregarAnteriores() {
    if (carregandoAnteriores || ! listaMensagens) {
        return;
    }

    const primeira = listaMensagens.querySelector('li[data-mensagem-id]');

    if (! primeira) {
        return;
    }

    carregandoAnteriores = true;
    botaoAnteriores?.setAttribute('aria-busy', 'true');

    fetch(urlHistorico({ antes_id: primeira.dataset.mensagemId }), {
        credentials: 'same-origin',
        headers: cabecalhosJson(),
    })
        .then((resposta) => (resposta.ok ? resposta.json() : Promise.reject(resposta)))
        .then((dados) => {
            (dados.mensagens || []).forEach((mensagem) => inserirMensagem(mensagem, { prepend: true, anunciar: false }));

            if (botaoAnteriores) {
                botaoAnteriores.hidden = ! dados.tem_anteriores;
            }
        })
        .catch(() => {
            mostrarAvisoSincronizacao(true);
        })
        .finally(() => {
            carregandoAnteriores = false;
            botaoAnteriores?.removeAttribute('aria-busy');
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
    enviarDigitacao(false);

    const echo = window.Echo;

    if (echo) {
        echo.leave(NOME_CANAL_PRESENCA);

        if (usuarioId) {
            echo.leave(`${prefixoCanal}App.Models.User.${usuarioId}`);
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

    conexao.bind('state_change', (estados) => {
        window.__chatDiagnostico.estado = estados?.current || conexao.state;
    });

    conexao.bind('connected', () => {
        window.__chatDiagnostico.estado = 'connected';

        if (! window.__chatSessaoEncerrada) {
            reconciliar('reconexao');
        }
    });

    conexao.bind('disconnected', () => {
        marcarPresencaIndisponivel();
        enviarDigitacao(false);
    });
    conexao.bind('unavailable', marcarPresencaIndisponivel);
    conexao.bind('failed', marcarPresencaIndisponivel);
}

function renderizarDigitacao() {
    if (! indicadorDigitacao) {
        return;
    }

    const nomes = [...digitandoPorUsuario.values()];

    if (nomes.length === 0) {
        indicadorDigitacao.hidden = true;
        indicadorDigitacao.textContent = '';

        return;
    }

    indicadorDigitacao.hidden = false;
    indicadorDigitacao.textContent = nomes.length === 1
        ? `${nomes[0]} está digitando…`
        : `${nomes.join(', ')} estão digitando…`;
}

function tratarDigitacao(payload) {
    if (! payload || Number(payload.conversa_id) !== Number(conversaId)) {
        return;
    }

    if (! payload.digitando) {
        digitandoPorUsuario.delete(Number(payload.usuario_id));
        renderizarDigitacao();

        return;
    }

    digitandoPorUsuario.set(Number(payload.usuario_id), payload.nome || 'Alguém');
    renderizarDigitacao();

    window.setTimeout(() => {
        const registro = digitandoPorUsuario.get(Number(payload.usuario_id));

        if (registro) {
            digitandoPorUsuario.delete(Number(payload.usuario_id));
            renderizarDigitacao();
        }
    }, expiraDigitacaoMs);
}

function enviarDigitacao(digitando) {
    if (! conversaId || frame?.dataset.bloqueada === '1') {
        return;
    }

    if (digitando && digitandoEnviado) {
        return;
    }

    if (! digitando && ! digitandoEnviado) {
        return;
    }

    digitandoEnviado = digitando;

    fetch('/digitacao', {
        method: 'POST',
        credentials: 'same-origin',
        headers: cabecalhosJson({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({
            conversa_id: Number(conversaId),
            digitando,
        }),
    }).catch(() => {
        // a expiração no cliente cobre falhas transitórias
    });
}

function aoDigitarComposer() {
    persistirRascunhoAtual();
    ajustarAlturaComposer();

    if (! conversaId) {
        return;
    }

    enviarDigitacao(true);
    window.clearTimeout(temporizadorDigitacao);
    temporizadorDigitacao = window.setTimeout(() => enviarDigitacao(false), expiraDigitacaoMs);
}

function perderAcessoGrupo(idAlvo = conversaId) {
    const id = Number(idAlvo);

    if (id) {
        revogadas.add(id);
    }

    const item = listaContatos?.querySelector(`li[data-conversa-id="${id}"]`);
    item?.remove();

    if (! id || Number(conversaId) !== id) {
        return;
    }

    if (formularioMensagem) {
        formularioMensagem.querySelectorAll('textarea, input, button').forEach((elemento) => {
            elemento.disabled = true;
        });
    }

    mostrarErroEnvio('Você não faz mais parte deste grupo.');
}

function tratarParticipante(payload) {
    if (! payload) {
        return;
    }

    if (payload.acao === 'removido' && Number(payload.usuario_id) === Number(usuarioId)) {
        perderAcessoGrupo(payload.conversa_id);
    }
}

function inscreverCanalPrivado(echo) {
    if (window.__chatCanalPrivado) {
        return;
    }

    window.__chatCanalPrivado = echo.private(`${prefixoCanal}App.Models.User.${usuarioId}`);

    window.__chatCanalPrivado
        .subscribed(() => {
            window.__chatDiagnostico.assinado = true;
            window.__chatDiagnostico.estado = window.Echo?.connector?.pusher?.connection?.state || 'connected';
            console.info(`Canal privado ${prefixoCanal}App.Models.User.${usuarioId} assinado.`);
            reconciliar('assinatura');
        })
        .listen('.diagnostico.pusher', (payload) => {
            registrarEventoPusher('diagnostico.pusher', payload);
            console.info('Diagnóstico Pusher recebido:', payload);
        })
        .listen('.mensagem.enviada', (payload) => {
            registrarEventoPusher('mensagem.enviada', payload);
            tratarEventoMensagem(payload, { anunciar: true });
        })
        .listen('.mensagem.alterada', (payload) => {
            registrarEventoPusher('mensagem.alterada', payload);
            tratarEventoMensagem(payload, { anunciar: false });
        })
        .listen('.participante.digitando', (payload) => {
            registrarEventoPusher('participante.digitando', payload);
            tratarDigitacao(payload);
        })
        .listen('.participante.atualizado', (payload) => {
            registrarEventoPusher('participante.atualizado', payload);
            tratarParticipante(payload);
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

function inicializarDialogoAcessivel({ modal, abrir, focoInicial, reabrirGaveta = false }) {
    if (! modal || ! abrir) {
        return;
    }

    let acionador = abrir;
    let ignorarFechamento = false;

    function abrirModal(origem) {
        acionador = origem || abrir;

        if (gavetaAberta()) {
            fecharGaveta({ devolverFoco: false });
        }

        if (typeof modal.showModal === 'function') {
            if (modal.open && ! modal.matches(':modal')) {
                ignorarFechamento = true;
                modal.removeAttribute('open');
                ignorarFechamento = false;
            }

            if (! modal.open) {
                modal.showModal();
            }
        } else if (! modal.open) {
            modal.setAttribute('open', '');
        }

        focoInicial?.focus();
    }

    function fecharModal() {
        if (typeof modal.close === 'function' && modal.open) {
            modal.close();

            return;
        }

        modal.removeAttribute('open');
        devolverFocoAoAcionador();
    }

    function devolverFocoAoAcionador() {
        if (reabrirGaveta && consultaGaveta.matches) {
            abrirGaveta();
        } else {
            document.body.style.overflow = '';
        }

        acionador?.focus();
    }

    abrir.addEventListener('click', (event) => {
        event.preventDefault();
        abrirModal(abrir);
    });

    modal.querySelectorAll('[data-fechar-dialogo]').forEach((elemento) => {
        elemento.addEventListener('click', (event) => {
            event.preventDefault();
            fecharModal();
        });
    });

    modal.addEventListener('close', () => {
        if (ignorarFechamento) {
            return;
        }

        devolverFocoAoAcionador();
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            fecharModal();
        }
    });

    if (modal.hasAttribute('open')) {
        abrirModal(abrir);
    }
}

function inicializarModalGrupo() {
    inicializarDialogoAcessivel({
        modal: document.getElementById('modal-criar-grupo'),
        abrir: document.getElementById('abrir-criar-grupo'),
        focoInicial: document.getElementById('nome-grupo'),
        reabrirGaveta: true,
    });
}

function inicializarModalMembros() {
    inicializarDialogoAcessivel({
        modal: document.getElementById('modal-membros-grupo'),
        abrir: document.getElementById('abrir-membros-grupo'),
        focoInicial: document.getElementById('titulo-membros-grupo'),
        reabrirGaveta: false,
    });
}

function aoTeclaGaveta(event) {
    if (document.getElementById('modal-criar-grupo')?.open || document.getElementById('modal-membros-grupo')?.open) {
        return;
    }

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

function limparAnexo() {
    if (campoAnexo) {
        campoAnexo.value = '';
    }

    if (previaAnexo) {
        previaAnexo.hidden = true;
        previaAnexo.replaceChildren();
    }
}

function mostrarPreviaAnexo(arquivo) {
    if (! previaAnexo || ! arquivo) {
        return;
    }

    const url = URL.createObjectURL(arquivo);
    const imagem = document.createElement('img');
    imagem.src = url;
    imagem.alt = 'Pré-visualização da imagem';
    previaAnexo.replaceChildren(imagem);
    previaAnexo.hidden = false;
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
    const arquivo = campoAnexo?.files?.[0] || null;
    const destinatario = formularioMensagem.querySelector('input[name="destinatario_id"]')?.value;
    const conversaCampo = formularioMensagem.querySelector('input[name="conversa_id"]')?.value;

    envioEmAndamento = true;
    botaoEnviar?.setAttribute('aria-busy', 'true');
    if (botaoEnviar) {
        botaoEnviar.disabled = true;
    }
    mostrarErroEnvio('');
    enviarDigitacao(false);

    const usarArquivo = Boolean(arquivo);
    let corpo;
    const headers = cabecalhosJson();

    if (usarArquivo) {
        const dados = new FormData();
        dados.set('conteudo', texto);

        if (destinatario) {
            dados.set('destinatario_id', destinatario);
        }

        if (conversaCampo) {
            dados.set('conversa_id', conversaCampo);
        }

        dados.set('anexo', arquivo);
        corpo = dados;
    } else {
        headers['Content-Type'] = 'application/json';
        corpo = JSON.stringify({
            destinatario_id: destinatario ? Number(destinatario) : undefined,
            conversa_id: conversaCampo ? Number(conversaCampo) : undefined,
            conteudo: texto,
        });
    }

    fetch(formularioMensagem.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: corpo,
    })
        .then(async (resposta) => {
            if (resposta.status === 201) {
                const dados = await resposta.json();
                tratarEventoMensagem(dados.mensagem, { anunciar: false });
                limparRascunhoSeCorrespondente(texto);
                limparAnexo();

                return;
            }

            if (resposta.status === 422) {
                const json = await resposta.json().catch(() => null);
                mostrarErroEnvio(primeiraMensagemValidacao(json));

                return;
            }

            if (resposta.status === 401 || resposta.status === 419) {
                mostrarErroEnvio('Sessão expirada. Entre novamente para enviar. O texto foi preservado.');

                return;
            }

            if (resposta.status === 403) {
                mostrarErroEnvio('Não foi possível enviar. Verifique o acesso à conversa.');

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

function aoTeclaComposer(event) {
    if (event.key !== 'Enter' || event.shiftKey || compondoIme || event.isComposing) {
        return;
    }

    if (tecladoVirtualMovel()) {
        return;
    }

    event.preventDefault();
    formularioMensagem?.requestSubmit();
}

function editarMensagem(id) {
    const item = listaMensagens?.querySelector(`[data-mensagem-id="${id}"]`);
    const atual = item?.querySelector('.mensagem-corpo > p')?.innerText || '';
    const proximo = window.prompt('Editar mensagem', atual);

    if (proximo === null) {
        return;
    }

    fetch(`/mensagens/${id}`, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: cabecalhosJson({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ conteudo: proximo }),
    })
        .then((resposta) => (resposta.ok ? resposta.json() : Promise.reject(resposta)))
        .then((dados) => tratarEventoMensagem(dados.mensagem, { anunciar: false }))
        .catch(() => mostrarErroEnvio('Não foi possível editar a mensagem.'));
}

function removerMensagem(id) {
    if (! window.confirm(TEXTO_CONFIRMAR_REMOCAO)) {
        return;
    }

    fetch(`/mensagens/${id}`, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: cabecalhosJson(),
    })
        .then((resposta) => (resposta.ok ? resposta.json() : Promise.reject(resposta)))
        .then((dados) => tratarEventoMensagem(dados.mensagem, { anunciar: false }))
        .catch(() => mostrarErroEnvio('Não foi possível remover a mensagem.'));
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
    enviarDigitacao(false);
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
    if (evento.key === CHAVE_SESSAO_ENCERRADA && evento.newValue) {
        tratarSinalLogout(evento.newValue);

        return;
    }

    if (evento.key === `${CHAVE_LEITURA}${usuarioId}` && evento.newValue) {
        try {
            const dados = JSON.parse(evento.newValue);
            aplicarLeituraLocal(dados.conversa_id, dados.ate_id);
        } catch (erro) {
            // Uma atualização de leitura inválida não deve interromper a sessão.
        }
    }
});

window.__chatCanalAbas?.addEventListener('message', (evento) => {
    if (evento.data?.conversa_id) {
        aplicarLeituraLocal(evento.data.conversa_id, evento.data.ate_id);
    }
});

campoConteudo?.addEventListener('input', aoDigitarComposer);
campoConteudo?.addEventListener('compositionstart', () => {
    compondoIme = true;
});
campoConteudo?.addEventListener('compositionend', () => {
    compondoIme = false;
});
campoConteudo?.addEventListener('keydown', aoTeclaComposer);

campoAnexo?.addEventListener('change', () => {
    const arquivo = campoAnexo.files?.[0];
    mostrarPreviaAnexo(arquivo);
});

formularioMensagem?.addEventListener('submit', enviarMensagemAssincrona);

listaMensagens?.addEventListener('click', (event) => {
    const editar = event.target.closest('.editar-mensagem');
    const remover = event.target.closest('.remover-mensagem');

    if (editar?.dataset.mensagemId) {
        event.preventDefault();
        editarMensagem(editar.dataset.mensagemId);
    }

    if (remover?.dataset.mensagemId) {
        event.preventDefault();
        removerMensagem(remover.dataset.mensagemId);
    }
});

listaMensagens?.addEventListener('submit', (event) => {
    const formulario = event.target.closest('.formulario-remover');

    if (! formulario || typeof window.fetch !== 'function') {
        return;
    }

    event.preventDefault();
    const id = event.submitter?.dataset.mensagemId
        || formulario.closest('li')?.dataset.mensagemId;

    if (id) {
        removerMensagem(id);
    }
});

botaoAnteriores?.addEventListener('click', carregarAnteriores);

document.addEventListener('visibilitychange', () => {
    if (abaVisivel()) {
        marcarLeitura();
    }
});

window.addEventListener('pagehide', () => enviarDigitacao(false));

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
inicializarModalGrupo();
inicializarModalMembros();

if (typeof consultaGaveta.addEventListener === 'function') {
    consultaGaveta.addEventListener('change', aoMudarBreakpointGaveta);
} else if (typeof consultaGaveta.addListener === 'function') {
    consultaGaveta.addListener(aoMudarBreakpointGaveta);
}

restaurarRascunhoInicial();

if (areaMensagens) {
    areaMensagens.scrollTop = areaMensagens.scrollHeight;
}

if ((contatoId || conversaId) && abaVisivel()) {
    marcarLeitura();
}

if (usuarioId && ! window.__chatSessaoEncerrada) {
    const echo = obterEcho();

    if (! echo) {
        marcarPresencaIndisponivel();
    } else {
        garantirListenersConexao(echo);
        inscreverCanalPrivado(echo);
        inscreverPresenca(echo);

        window.__chatDesconectarPusher = () => {
            echo.connector?.pusher?.disconnect();
        };

        window.__chatReconectarPusher = () => {
            echo.connector?.pusher?.connect();
        };
    }
}
