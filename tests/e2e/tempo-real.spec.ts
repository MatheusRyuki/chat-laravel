import { test, expect } from '@playwright/test';
import {
    aguardarCanalPrivado,
    contextoAutenticado,
    diagnosticoCliente,
    enviarTexto,
    esgotarHistoricoAnterior,
    eventoPusherComConteudo,
    itemContato,
    monitorarHttp,
} from './helpers';

declare global {
    interface Window {
        __chatDiagnostico: {
            prefixoCanal: string;
            asinado: boolean;
            estado: string;
            canal: string;
            eventosPusher: { nome: string; conteudo: string | null; conversa_id: number | null; acao: string | null }[];
            reconciliacoes: { origem: string; t: number }[];
        };
        __chatDesconectarPusher?: () => void;
        __chatReconectarPusher?: () => void;
    }
}

test.describe.configure({ mode: 'default' });

test('diagnostico do tempo real isolado: prefixo, auth, workers e evento identificado', async ({ browser, request }) => {
    const servidor = await request.get('/e2e/diagnostico');
    expect(servidor.ok()).toBeTruthy();
    const config = await servidor.json();

    expect(config.broadcast).toBe('pusher');
    expect(config.prefixo_canal).toBe('e2e-');
    expect(config.pusher_key_configurada).toBe(true);
    expect(config.session_driver).toBe('file');
    expect(Number(config.php_cli_server_workers)).toBeGreaterThanOrEqual(2);

    const inicio = Date.now();
    const atrasar = request.get('/e2e/atrasar');
    const ping = await request.get('/e2e/diagnostico');
    const pingMs = Date.now() - inicio;
    await atrasar;

    expect(ping.ok()).toBeTruthy();
    expect(pingMs, `ping durante atraso deveria ser concorrente, levou ${pingMs}ms`).toBeLessThan(800);

    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const httpAna = monitorarHttp(ana.pagina);
    await ana.pagina.goto('/?contato=2');
    await aguardarCanalPrivado(ana.pagina);

    const cliente = await diagnosticoCliente(ana.pagina);
    expect(cliente.prefixoCanal).toBe('e2e-');
    expect(cliente.canal).toBe('e2e-App.Models.User.1');
    expect(cliente.assinado).toBe(true);

    const auth = httpAna.filter((item) => item.url.includes('/broadcasting/auth') && item.status);
    expect(auth.some((item) => item.status === 200)).toBeTruthy();

    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');
    await bruno.pagina.goto('/?contato=1');
    await aguardarCanalPrivado(bruno.pagina);

    const marca = `evento-identificado-${Date.now()}`;
    await enviarTexto(bruno.pagina, marca);
    await eventoPusherComConteudo(ana.pagina, 'mensagem.enviada', marca);

    const depois = await diagnosticoCliente(ana.pagina);
    const recebido = depois.eventosPusher.find((evento) => evento.nome === 'mensagem.enviada' && String(evento.conteudo).includes(marca));
    expect(recebido, 'o evento identificado deveria chegar pelo Pusher').toBeTruthy();

    await expect(itemContato(ana.pagina, 'Bruno E2E').locator('.previa')).toContainText(marca);

    await ana.contexto.close();
    await bruno.contexto.close();
});

test('N1-02 ao vivo: C envia para A enquanto A fala com B, sem reload', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');
    const carla = await contextoAutenticado(browser, 'carla.e2e@example.com');
    const httpAna = monitorarHttp(ana.pagina);

    await ana.pagina.goto('/?contato=2');
    await aguardarCanalPrivado(ana.pagina);
    await ana.pagina.fill('#campo-conteudo', 'rascunho com B');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('Histórico janela 110');

    await carla.pagina.goto('/?contato=1');
    await aguardarCanalPrivado(carla.pagina);

    const marca = `carla-ao-vivo-${Date.now()}`;
    const reconciliacoesAntes = (await diagnosticoCliente(ana.pagina)).reconciliacoes.length;
    const envioEm = Date.now();
    await enviarTexto(carla.pagina, marca);

    await eventoPusherComConteudo(ana.pagina, 'mensagem.enviada', marca);
    await expect(itemContato(ana.pagina, 'Carla E2E').locator('.previa')).toContainText(marca);
    await expect(itemContato(ana.pagina, 'Carla E2E').locator('.nao-lidas')).toBeVisible();
    await expect(itemContato(ana.pagina, 'Carla E2E')).toHaveClass(/contact/);
    await expect(ana.pagina.locator('#contacts .contact').first()).toContainText('Carla E2E');
    await expect(ana.pagina.locator('#campo-conteudo')).toHaveValue('rascunho com B');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('Histórico janela 110');
    await expect(ana.pagina.locator('#lista-mensagens')).not.toContainText(marca);

    const depois = await diagnosticoCliente(ana.pagina);
    const evento = depois.eventosPusher.find((item) => item.nome === 'mensagem.enviada' && String(item.conteudo).includes(marca));
    expect(evento).toBeTruthy();

    const getDepoisDoEnvio = httpAna.filter((item) => item.metodo === 'GET'
        && item.url.includes('/mensagens')
        && item.t >= envioEm
        && item.status);
    const reconciliacoesNovas = depois.reconciliacoes.length - reconciliacoesAntes;

    expect(reconciliacoesNovas, 'a prévia ao vivo não deve depender de reconciliação HTTP').toBe(0);
    expect(
        getDepoisDoEnvio.filter((item) => /versao=/.test(item.url)).length,
        `GET /mensagens?versao após o envio: ${JSON.stringify(getDepoisDoEnvio)}`,
    ).toBe(0);

    await ana.contexto.close();
    await bruno.contexto.close();
    await carla.contexto.close();
});

test('digitação ao vivo expira, limpa ao enviar e ao trocar de conversa', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');

    await ana.pagina.goto('/?contato=2');
    await bruno.pagina.goto('/?contato=1');
    await aguardarCanalPrivado(ana.pagina);
    await aguardarCanalPrivado(bruno.pagina);

    await ana.pagina.fill('#campo-conteudo', 'estou digitando agora');
    await bruno.pagina.waitForFunction(
        () => window.__chatDiagnostico?.eventosPusher?.some((evento) => evento.nome === 'participante.digitando' && evento.digitando === true),
        null,
        { timeout: 15_000 },
    );
    await expect(bruno.pagina.locator('#indicador-digitacao')).toContainText('Ana E2E');

    await expect(bruno.pagina.locator('#indicador-digitacao')).toBeHidden({ timeout: 8_000 });

    await ana.pagina.fill('#campo-conteudo', 'segunda digitação');
    await expect(bruno.pagina.locator('#indicador-digitacao')).toContainText('Ana E2E', { timeout: 15_000 });
    await enviarTexto(ana.pagina, 'envio encerra digitação');
    await expect(bruno.pagina.locator('#indicador-digitacao')).toBeHidden({ timeout: 8_000 });

    await ana.pagina.fill('#campo-conteudo', 'antes de trocar');
    await expect(bruno.pagina.locator('#indicador-digitacao')).toContainText('Ana E2E', { timeout: 15_000 });
    await ana.pagina.goto('/?contato=3');
    await expect(bruno.pagina.locator('#indicador-digitacao')).toBeHidden({ timeout: 8_000 });

    await ana.contexto.close();
    await bruno.contexto.close();
});

test('remoção de membro conectado: restantes recebem, removido não', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');
    const carla = await contextoAutenticado(browser, 'carla.e2e@example.com');

    await ana.pagina.goto('/');
    await ana.pagina.locator('#criar-grupo summary').click();
    await ana.pagina.fill('#nome-grupo', 'Grupo Ao Vivo');
    await ana.pagina.locator('#criar-grupo input[type="checkbox"]').nth(0).check();
    await ana.pagina.locator('#criar-grupo input[type="checkbox"]').nth(1).check();
    await ana.pagina.locator('#criar-grupo button[type="submit"]').click();
    await expect(ana.pagina.locator('.contact-profile')).toContainText('Grupo Ao Vivo');
    await aguardarCanalPrivado(ana.pagina);

    const urlGrupo = ana.pagina.url();
    await bruno.pagina.goto(urlGrupo);
    await carla.pagina.goto(urlGrupo);
    await aguardarCanalPrivado(bruno.pagina);
    await aguardarCanalPrivado(carla.pagina);

    await ana.pagina.locator('.gestao-grupo li', { hasText: 'Carla E2E' }).getByRole('button', { name: 'Remover' }).click();
    await expect(carla.pagina.locator('#erro-envio')).toContainText('não faz mais parte', { timeout: 15_000 });

    const pusherCarlaAntes = (await diagnosticoCliente(carla.pagina)).eventosPusher.length;
    await aguardarCanalPrivado(ana.pagina);
    await enviarTexto(ana.pagina, 'depois da remoção só autorizados');

    await eventoPusherComConteudo(bruno.pagina, 'mensagem.enviada', 'depois da remoção só autorizados');
    await expect(bruno.pagina.locator('#lista-mensagens')).toContainText('depois da remoção só autorizados');

    await carla.pagina.waitForTimeout(1500);
    const pusherCarla = await diagnosticoCliente(carla.pagina);
    const novas = pusherCarla.eventosPusher.slice(pusherCarlaAntes);
    expect(novas.some((evento) => String(evento.conteudo || '').includes('depois da remoção só autorizados'))).toBeFalsy();
    await expect(carla.pagina.locator('#lista-mensagens')).not.toContainText('depois da remoção só autorizados');

    const httpRemovido = await carla.pagina.evaluate(async () => {
        const resposta = await fetch(`${window.location.pathname}${window.location.search}`.includes('grupo')
            ? `/mensagens?conversa=${document.getElementById('frame')?.getAttribute('data-conversa-id')}`
            : '/mensagens', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        return { status: resposta.status };
    });
    expect(httpRemovido.status).toBeGreaterThanOrEqual(400);

    await ana.contexto.close();
    await bruno.contexto.close();
    await carla.contexto.close();
});

test('bloqueio recusa envio 1:1 e preserva outro contato ao vivo', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');
    const carla = await contextoAutenticado(browser, 'carla.e2e@example.com');

    await ana.pagina.goto('/?contato=3');
    await ana.pagina.locator('.formulario-bloqueio button').click();
    await expect(ana.pagina.locator('.aviso-bloqueio')).toContainText('grupos compartilhados');

    await ana.pagina.goto('/?contato=2');
    await aguardarCanalPrivado(ana.pagina);

    await carla.pagina.goto('/?contato=1');
    await expect(carla.pagina.locator('#orientacao-composer')).toContainText('bloqueio');
    const recusa = await carla.pagina.evaluate(async () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const destinatario = document.getElementById('frame')?.dataset.contatoId;
        const resposta = await fetch('/mensagens', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token || '',
            },
            body: JSON.stringify({
                destinatario_id: Number(destinatario),
                conteudo: 'não deve passar',
            }),
        });

        return resposta.status;
    });
    expect(recusa).toBeGreaterThanOrEqual(400);

    await bruno.pagina.goto('/?contato=1');
    await aguardarCanalPrivado(bruno.pagina);
    const marca = `bruno-apos-bloqueio-${Date.now()}`;
    await enviarTexto(bruno.pagina, marca);
    await eventoPusherComConteudo(ana.pagina, 'mensagem.enviada', marca);
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText(marca);

    await ana.pagina.goto('/profile');
    await ana.pagina.getByRole('button', { name: 'Desbloquear' }).click();

    await ana.contexto.close();
    await bruno.contexto.close();
    await carla.contexto.close();
});

test('leitura: aba oculta no Chromium permanece não lida; aba visível sincroniza', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');

    const anaFundo = await ana.contexto.newPage();
    const httpFundo = monitorarHttp(anaFundo);
    const httpFrente = monitorarHttp(ana.pagina);

    await anaFundo.goto('/?contato=2');
    await aguardarCanalPrivado(anaFundo);
    await ana.pagina.goto('/?contato=3');
    await aguardarCanalPrivado(ana.pagina);
    await ana.pagina.bringToFront();
    await anaFundo.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
        document.dispatchEvent(new Event('visibilitychange'));
    });

    const visibilidade = await Promise.all([
        anaFundo.evaluate(() => document.visibilityState),
        ana.pagina.evaluate(() => document.visibilityState),
    ]);
    expect(visibilidade[0], 'simulação de document.visibilityState=hidden no Chromium; não é janela minimizada do SO').toBe('hidden');
    expect(visibilidade[1]).toBe('visible');

    await bruno.pagina.goto('/?contato=1');
    await aguardarCanalPrivado(bruno.pagina);
    const marca = `nao-lida-fundo-${Date.now()}`;
    const envioEm = Date.now();
    await enviarTexto(bruno.pagina, marca);

    await eventoPusherComConteudo(anaFundo, 'mensagem.enviada', marca);
    await expect(itemContato(anaFundo, 'Bruno E2E').locator('.nao-lidas')).toBeVisible();

    const leiturasFundo = httpFundo.filter((item) => item.url.includes('/leituras') && item.metodo === 'POST' && item.t >= envioEm && item.status);
    expect(leiturasFundo.filter((item) => item.status === 200).length).toBe(0);

    await anaFundo.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await anaFundo.bringToFront();
    await expect(itemContato(anaFundo, 'Bruno E2E').locator('.nao-lidas')).toBeHidden({ timeout: 15_000 });
    await expect.poll(() => httpFundo.some((item) => item.url.includes('/leituras') && item.metodo === 'POST' && (item.status ?? 0) === 200)).toBeTruthy();

    await ana.pagina.bringToFront();
    await ana.pagina.goto('/');
    await expect(itemContato(ana.pagina, 'Bruno E2E').locator('.nao-lidas')).toBeHidden({ timeout: 15_000 });

    const conversaId = await anaFundo.evaluate(() => Number(document.getElementById('frame')?.dataset.conversaId));
    const ateAntigo = await ana.pagina.evaluate(async (conversa) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const resposta = await fetch('/leituras', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token || '',
            },
            body: JSON.stringify({ conversa_id: conversa, ate_id: 1 }),
        });

        return resposta.status;
    }, conversaId);
    expect(ateAntigo).toBe(200);
    await expect(itemContato(ana.pagina, 'Bruno E2E').locator('.nao-lidas')).toBeHidden();

    void httpFrente;

    await ana.contexto.close();
    await bruno.contexto.close();
});

test('reconciliação HTTP recupera edição e exclusão após perda de eventos, sem reload', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');

    await ana.pagina.goto('/?contato=2');
    await aguardarCanalPrivado(ana.pagina);
    await expect(ana.pagina.locator('#lista-mensagens li[data-mensagem-id]')).toHaveCount(50);

    const rolagemAntes = await ana.pagina.locator('.messages').evaluate((el) => el.scrollTop);
    await esgotarHistoricoAnterior(ana.pagina);
    await expect(ana.pagina.getByText('Histórico janela 1', { exact: true })).toBeVisible();
    const rolagemDepoisCarregar = await ana.pagina.locator('.messages').evaluate((el) => el.scrollTop);
    expect(rolagemDepoisCarregar).not.toBe(rolagemAntes);

    await bruno.pagina.goto('/?contato=1');
    await aguardarCanalPrivado(bruno.pagina);
    await enviarTexto(bruno.pagina, 'alvo-edicao-reconcilia');
    await enviarTexto(bruno.pagina, 'alvo-remocao-reconcilia');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('alvo-edicao-reconcilia');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('alvo-remocao-reconcilia');

    await ana.pagina.evaluate(() => window.__chatDesconectarPusher?.());
    await ana.pagina.waitForFunction(
        () => window.Echo?.connector?.pusher?.connection?.state !== 'connected',
        null,
        { timeout: 10_000 },
    );

    const pusherAntes = (await diagnosticoCliente(ana.pagina)).eventosPusher.length;

    await bruno.pagina.reload();
    await expect(bruno.pagina.locator('#lista-mensagens')).toContainText('alvo-edicao-reconcilia');
    bruno.pagina.once('dialog', (dialog) => dialog.accept('texto-editado-na-desconexao'));
    await bruno.pagina.locator('#lista-mensagens li', { hasText: 'alvo-edicao-reconcilia' }).locator('.editar-mensagem').click();
    await expect(bruno.pagina.locator('#lista-mensagens')).toContainText('texto-editado-na-desconexao');
    bruno.pagina.once('dialog', (dialog) => dialog.accept());
    await bruno.pagina.locator('#lista-mensagens li', { hasText: 'alvo-remocao-reconcilia' }).locator('.remover-mensagem').click();
    await expect(bruno.pagina.locator('#lista-mensagens')).toContainText('Mensagem removida');

    const reconciliacoesAntes = (await diagnosticoCliente(ana.pagina)).reconciliacoes.length;
    await ana.pagina.evaluate(() => window.__chatReconectarPusher?.());
    await aguardarCanalPrivado(ana.pagina);

    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('texto-editado-na-desconexao');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('Mensagem removida');
    await expect(ana.pagina.locator('#lista-mensagens')).not.toContainText('alvo-remocao-reconcilia');
    await expect(ana.pagina.getByText('Histórico janela 1', { exact: true })).toBeVisible();

    const ids = await ana.pagina.locator('#lista-mensagens li[data-mensagem-id]').evaluateAll(
        (itens) => itens.map((item) => item.getAttribute('data-mensagem-id')),
    );
    expect(new Set(ids).size).toBe(ids.length);

    const depois = await diagnosticoCliente(ana.pagina);
    const novosPusher = depois.eventosPusher.slice(pusherAntes).filter((evento) => evento.nome === 'mensagem.alterada');
    const reconexoes = depois.reconciliacoes.slice(reconciliacoesAntes).filter((item) => item.origem === 'reconexao' || item.origem === 'assinatura');
    expect(reconexoes.length, 'recuperação deve passar por HTTP de reconciliação').toBeGreaterThan(0);
    expect(
        novosPusher.some((evento) => String(evento.conteudo || '').includes('texto-editado-na-desconexao')),
        'edição durante a desconexão não deve ter chegado pelo Pusher',
    ).toBeFalsy();

    await ana.contexto.close();
    await bruno.contexto.close();
});
