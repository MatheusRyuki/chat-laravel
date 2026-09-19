import { obterEcho } from './echo';

const frame = document.getElementById('frame');
const usuarioId = frame?.dataset.userId;
const contatoId = frame?.dataset.contatoId;
const areaMensagens = document.querySelector('.messages');
const listaMensagens = document.getElementById('lista-mensagens');
const indicadorCarregamento = document.getElementById('conversation-loader');
const LIMIAR_ROLAGEM = 80;

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

function inserirMensagem(payload) {
    if (! listaMensagens || ! pertenceAConversaAberta(payload)) {
        return;
    }

    if (listaMensagens.querySelector(`[data-mensagem-id="${payload.id}"]`)) {
        return;
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

    item.appendChild(imagem);
    item.appendChild(paragrafoSeguro(payload.conteudo));

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
}

function obterRascunho() {
    return document.querySelector('.message-input input[name="conteudo"]')?.value;
}

function restaurarRascunho(rascunho) {
    const campo = document.querySelector('.message-input input[name="conteudo"]');

    if (campo && rascunho !== undefined) {
        campo.value = rascunho;
    }
}

function reconciliar() {
    if (! contatoId) {
        return;
    }

    const rascunho = obterRascunho();
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    fetch(`/mensagens?contato=${encodeURIComponent(contatoId)}`, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
    })
        .then((resposta) => (resposta.ok ? resposta.json() : Promise.reject()))
        .then((dados) => {
            (dados.mensagens || []).forEach(inserirMensagem);
            restaurarRascunho(rascunho);
        })
        .catch(() => {
            restaurarRascunho(rascunho);
        });
}

const NOME_CANAL_PRESENCA = 'presenca.chat';
const CHAVE_SESSAO_ENCERRADA = 'chat-sessao-encerrada';
const ROTULOS_PRESENCA = {
    aguardando: 'Presença a confirmar',
    online: 'Online',
    offline: 'Offline',
    indisponivel: 'Presença indisponível',
};

let presencaConfirmada = false;
let presencaIndisponivel = false;

function indicadoresPresenca() {
    return document.querySelectorAll('[data-presenca-usuario]');
}

function aplicarEstadoPresenca(elemento, estado) {
    elemento.classList.remove('aguardando', 'online', 'offline', 'indisponivel');
    elemento.classList.add(estado);

    const rotulo = ROTULOS_PRESENCA[estado] || ROTULOS_PRESENCA.aguardando;

    elemento.setAttribute('aria-label', rotulo);

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
            inserirMensagem(payload);
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

document.getElementById('contacts')?.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');

    if (! link || ! navegacaoMesmaAba(event, link)) {
        return;
    }

    mostrarCarregamento();
});

window.addEventListener('pageshow', () => {
    ocultarCarregamento();
});

document.getElementById('formulario-sair')?.addEventListener('submit', () => {
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

    encerrarConexoesChat();
    marcarPresencaIndisponivel();
});

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
