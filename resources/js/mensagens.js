export function compararMensagens(a, b) {
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

export function formatarHorarioMensagem(iso, fusoApp = 'UTC', agora = new Date()) {
    if (! iso) {
        return '';
    }

    const momento = new Date(iso);

    if (Number.isNaN(momento.getTime())) {
        return '';
    }

    const atual = partesNoFuso(agora, fusoApp);
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

export function previaDePayload(payload) {
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

