import { test, expect } from '@playwright/test';
import path from 'node:path';
import { mkdirSync } from 'node:fs';
import os from 'node:os';
import {
    aguardarCanalPrivado,
    abrirModalCriarGrupo,
    assertLayoutAnexo,
    capturas,
    contextoAutenticado,
    entrar,
    enviarTexto,
    escreverPngSolido,
    estruturaDoCorpo,
    estruturaDoItem,
    eventoPusherComConteudo,
    itemContato,
    retangulosNaoSeSobrepoem,
    textosDoHistorico,
} from './helpers';

test('lista por atividade, outra conversa, rascunho e abas da mesma conta', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');
    await ana.contexto.newPage().then((aba) => aba.goto('/'));

    await ana.pagina.goto('/?contato=3');
    await aguardarCanalPrivado(ana.pagina);
    await ana.pagina.fill('#campo-conteudo', 'rascunho preservado');

    await bruno.pagina.goto('/?contato=1');
    await aguardarCanalPrivado(bruno.pagina);
    await enviarTexto(bruno.pagina, 'Olá da outra conversa');

    await eventoPusherComConteudo(ana.pagina, 'mensagem.enviada', 'Olá da outra conversa');
    await expect(ana.pagina.locator('#campo-conteudo')).toHaveValue('rascunho preservado');
    await expect(itemContato(ana.pagina, 'Bruno E2E').locator('.previa'))
        .toContainText('Olá da outra conversa');

    mkdirSync(capturas, { recursive: true });
    await ana.pagina.screenshot({ path: path.join(capturas, 'lista-atividade.png'), fullPage: true });

    await ana.contexto.close();
    await bruno.contexto.close();
});

test('compositor multilinha, links e envio sem limpar texto em erro', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    await ana.pagina.goto('/?contato=2');

    const campo = ana.pagina.locator('#campo-conteudo');
    await expect(campo).toHaveAttribute('maxlength', '1000');
    await campo.fill('linha 1');
    await campo.press('Shift+Enter');
    await campo.type('linha 2');
    await expect(campo).toHaveValue('linha 1\nlinha 2');

    await enviarTexto(ana.pagina, 'veja https://exemplo.com e <b>html</b>');
    await expect(ana.pagina.locator('#lista-mensagens a[rel="noopener noreferrer"]')).toHaveAttribute('target', '_blank');
    await expect(ana.pagina.locator('#lista-mensagens b')).toHaveCount(0);
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('<b>html</b>');

    await ana.pagina.screenshot({ path: path.join(capturas, 'composer-e-links.png'), fullPage: true });
    await ana.contexto.close();
});

test('não lidas persistidas entre abas e leitura com aba visível', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');
    const anaFundo = await ana.contexto.newPage();
    await anaFundo.goto('/?contato=3');

    await bruno.pagina.goto('/?contato=1');
    await enviarTexto(bruno.pagina, 'para contar não lida');

    await ana.pagina.goto('/');
    const itemBruno = ana.pagina.locator('#contacts .contact', { hasText: 'Bruno E2E' });
    await expect(itemBruno.locator('.previa')).toContainText('para contar não lida');
    await expect(itemBruno.locator('.nao-lidas')).toBeVisible();

    await ana.pagina.goto('/?contato=2');
    await ana.pagina.bringToFront();
    await expect(ana.pagina.locator('#contacts .contact.active .nao-lidas')).toBeHidden({ timeout: 15_000 });

    await ana.pagina.screenshot({ path: path.join(capturas, 'nao-lidas.png'), fullPage: true });
    await ana.contexto.close();
    await bruno.contexto.close();
});

test('histórico com mais de uma página anterior, ordem e botão oculto', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');

    await ana.pagina.goto('/');
    await expect(ana.pagina.locator('#carregar-anteriores')).toBeHidden();
    const botaoHomeNoTab = await ana.pagina.locator('#carregar-anteriores').evaluate((botao) => {
        botao.focus();

        return document.activeElement === botao;
    });
    expect(botaoHomeNoTab, 'botão hidden não deve receber foco').toBeFalsy();

    await ana.pagina.goto('/?contato=3');
    await expect(ana.pagina.locator('#carregar-anteriores')).toBeHidden();
    await expect(ana.pagina.locator('.messages-empty')).toBeVisible();

    const hrefEva = await itemContato(ana.pagina, 'Eva E2E').locator('a').getAttribute('href');
    expect(hrefEva).toBeTruthy();
    await ana.pagina.goto(hrefEva!);
    await expect(ana.pagina.locator('#lista-mensagens li[data-mensagem-id]')).toHaveCount(50);
    await expect(ana.pagina.locator('#lista-mensagens').getByText('Histórico janela 110', { exact: true })).toBeVisible();
    await expect(ana.pagina.locator('#lista-mensagens').getByText('Histórico janela 1', { exact: true })).toHaveCount(0);

    const nome = ana.pagina.locator('.cabecalho-conversa p').first();
    const email = ana.pagina.locator('.contato-email');
    await expect(nome).toHaveText('Eva E2E');
    await expect(email).toHaveText('eva.e2e@example.com');
    const caixaNome = await nome.boundingBox();
    const caixaEmail = await email.boundingBox();
    expect(caixaNome && caixaEmail).toBeTruthy();
    expect(caixaEmail!.y).toBeGreaterThan(caixaNome!.y);

    const botao = ana.pagina.locator('#carregar-anteriores');
    await expect(botao).toBeVisible();
    const rolagemAntes = await ana.pagina.locator('.messages').evaluate((el) => el.scrollTop);
    const primeiroLote = ana.pagina.waitForResponse((resposta) => resposta.url().includes('/mensagens')
        && resposta.url().includes('antes_id')
        && resposta.request().method() === 'GET');
    await botao.click();
    await primeiroLote;
    await expect(ana.pagina.locator('#lista-mensagens').getByText('Histórico janela 11', { exact: true })).toBeVisible();
    await expect(ana.pagina.locator('#lista-mensagens').getByText('Histórico janela 1', { exact: true })).toHaveCount(0);
    await expect(botao).toBeVisible();

    const depoisPrimeiroLote = await textosDoHistorico(ana.pagina);
    expect(depoisPrimeiroLote.slice(0, 50)).toEqual(
        Array.from({ length: 50 }, (_, indice) => `Histórico janela ${indice + 11}`),
    );
    expect(depoisPrimeiroLote.at(-1)).toBe('Histórico janela 110');

    const rolagemDepois = await ana.pagina.locator('.messages').evaluate((el) => el.scrollTop);
    expect(rolagemDepois).not.toBe(rolagemAntes);

    const segundoLote = ana.pagina.waitForResponse((resposta) => resposta.url().includes('/mensagens')
        && resposta.url().includes('antes_id')
        && resposta.request().method() === 'GET');
    await botao.click();
    await segundoLote;
    await expect(ana.pagina.locator('#lista-mensagens').getByText('Histórico janela 1', { exact: true })).toBeVisible();
    await expect(botao).toBeHidden();

    const sequencia = await textosDoHistorico(ana.pagina);
    expect(sequencia).toEqual(Array.from({ length: 110 }, (_, indice) => `Histórico janela ${indice + 1}`));

    await enviarTexto(ana.pagina, 'depois dos lotes');
    const comNova = await textosDoHistorico(ana.pagina);
    expect(comNova[0]).toBe('Histórico janela 1');
    expect(comNova.at(-1)).toBe('depois dos lotes');

    await ana.pagina.screenshot({ path: path.join(capturas, 'historico-paginado.png'), fullPage: true });
    await ana.contexto.close();
});

test('editar e remover mensagem própria', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    await ana.pagina.goto('/?contato=3');
    await enviarTexto(ana.pagina, 'mensagem editável');

    const chromeAoVivo = await estruturaDoCorpo(ana.pagina, 'mensagem editável');
    expect(chromeAoVivo[0]).toBe('p');
    expect(chromeAoVivo).toContain('time.mensagem-horario');
    expect(chromeAoVivo.at(-1)).toBe('div.acoes-mensagem');
    expect(chromeAoVivo.indexOf('time.mensagem-horario')).toBeLessThan(chromeAoVivo.lastIndexOf('div.acoes-mensagem'));

    await ana.pagina.reload();
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('mensagem editável');
    const chromeServidor = await estruturaDoCorpo(ana.pagina, 'mensagem editável');
    expect(chromeServidor).toEqual(chromeAoVivo);

    ana.pagina.once('dialog', (dialog) => dialog.accept('mensagem já editada'));
    await ana.pagina.locator('.editar-mensagem').last().click();
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('mensagem já editada');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('Editada');

    const chromeEditada = await estruturaDoCorpo(ana.pagina, 'mensagem já editada');
    expect(chromeEditada).toContain('span.mensagem-editada');
    expect(chromeEditada.indexOf('span.mensagem-editada')).toBeLessThan(chromeEditada.indexOf('time.mensagem-horario'));
    expect(chromeEditada.indexOf('time.mensagem-horario')).toBeLessThan(chromeEditada.lastIndexOf('div.acoes-mensagem'));

    const exclusoes: string[] = [];
    ana.pagina.on('request', (pedido) => {
        if (pedido.method() === 'DELETE' && pedido.url().includes('/mensagens/')) {
            exclusoes.push(pedido.url());
        }
    });

    ana.pagina.once('dialog', (dialog) => dialog.dismiss());
    await ana.pagina.locator('.remover-mensagem').last().click();
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('mensagem já editada');
    await expect(ana.pagina.locator('#lista-mensagens')).not.toContainText('Mensagem removida');
    expect(exclusoes, 'cancelar não envia DELETE').toHaveLength(0);

    ana.pagina.once('dialog', async (dialog) => {
        expect(dialog.message()).toContain('participantes da conversa');
        await dialog.accept();
    });
    await ana.pagina.locator('.remover-mensagem').last().click();
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('Mensagem removida');
    expect(exclusoes).toHaveLength(1);

    await ana.pagina.reload();
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('Mensagem removida');

    await enviarTexto(ana.pagina, 'remover pelo teclado');
    await ana.pagina.reload();
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('remover pelo teclado');
    const itemTeclado = ana.pagina.locator('#lista-mensagens li', { hasText: 'remover pelo teclado' });
    const idTeclado = await itemTeclado.getAttribute('data-mensagem-id');
    expect(idTeclado).toBeTruthy();
    const removerTeclado = itemTeclado.locator('.remover-mensagem');
    ana.pagina.once('dialog', (dialog) => {
        expect(dialog.message()).toContain('participantes da conversa');
        void dialog.accept();
    });
    await removerTeclado.focus();
    await expect(removerTeclado).toBeFocused();
    await removerTeclado.press('Enter');
    await expect(ana.pagina.locator(`#lista-mensagens li[data-mensagem-id="${idTeclado}"]`)).toContainText('Mensagem removida');
    await expect(ana.pagina.locator(`#lista-mensagens li[data-mensagem-id="${idTeclado}"]`)).not.toContainText('remover pelo teclado');

    await enviarTexto(ana.pagina, 'remover no cliente');
    const itemCliente = ana.pagina.locator('#lista-mensagens li', { hasText: 'remover no cliente' });
    const idCliente = await itemCliente.getAttribute('data-mensagem-id');
    ana.pagina.once('dialog', (dialog) => dialog.accept());
    await itemCliente.locator('.remover-mensagem').click();
    await expect(ana.pagina.locator(`#lista-mensagens li[data-mensagem-id="${idCliente}"]`)).toContainText('Mensagem removida');
    await expect(ana.pagina.locator('#lista-mensagens')).not.toContainText('remover no cliente');

    await ana.pagina.screenshot({ path: path.join(capturas, 'editar-remover.png'), fullPage: true });
    await ana.contexto.close();
});

test('grupo com três participantes e recusa do quarto', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const davi = await contextoAutenticado(browser, 'davi.e2e@example.com');

    await ana.pagina.goto('/');
    await abrirModalCriarGrupo(ana.pagina);
    await ana.pagina.screenshot({ path: path.join(capturas, 'novo-grupo.png'), fullPage: true });

    await ana.pagina.fill('#nome-grupo', 'Time E2E');
    await ana.pagina.locator('#modal-criar-grupo input[type="checkbox"]').nth(0).check();
    await ana.pagina.locator('#modal-criar-grupo input[type="checkbox"]').nth(1).check();
    await ana.pagina.locator('#modal-criar-grupo button[type="submit"]').click();
    await expect(ana.pagina.locator('.contact-profile')).toContainText('Time E2E');
    await expect(ana.pagina.locator('.aviso-status')).toContainText('histórico');

    await enviarTexto(ana.pagina, 'olá grupo e2e');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('olá grupo e2e');

    const href = ana.pagina.url();
    const resposta = await davi.pagina.goto(href);
    expect(resposta?.status()).toBe(404);

    await ana.pagina.screenshot({ path: path.join(capturas, 'grupo.png'), fullPage: true });

    await ana.pagina.setViewportSize({ width: 1100, height: 520 });
    await expect(ana.pagina.locator('#campo-conteudo')).toBeVisible();
    await expect(ana.pagina.locator('.gestao-grupo')).toBeVisible();
    const compositorAlto = await ana.pagina.locator('.message-input').boundingBox();
    const gestaoAlta = await ana.pagina.locator('.gestao-grupo').boundingBox();
    expect(compositorAlto && gestaoAlta).toBeTruthy();
    await retangulosNaoSeSobrepoem(compositorAlto!, gestaoAlta!);

    await ana.pagina.setViewportSize({ width: 734, height: 520 });
    await expect(ana.pagina.locator('#sidebar-toggle')).toBeVisible();
    await expect(ana.pagina.locator('#campo-conteudo')).toBeVisible();
    await expect(ana.pagina.locator('.gestao-grupo')).toBeVisible();
    await ana.pagina.locator('#sidebar-toggle').click();
    await expect(ana.pagina.locator('#frame')).toHaveClass(/sidebar-expanded/);
    await abrirModalCriarGrupo(ana.pagina);
    await expect(ana.pagina.locator('#frame')).not.toHaveClass(/sidebar-expanded/);
    await ana.pagina.screenshot({ path: path.join(capturas, 'novo-grupo-celular.png') });
    await ana.pagina.locator('#modal-criar-grupo [data-fechar-modal-grupo]').first().click();
    await expect(ana.pagina.locator('#modal-criar-grupo')).toBeHidden();
    await expect(ana.pagina.locator('#abrir-criar-grupo')).toBeFocused();
    await expect(ana.pagina.locator('#campo-conteudo')).toBeEnabled();

    await ana.contexto.close();
    await davi.contexto.close();
});

test('modal de grupo cancela sem enviar, preserva dados e mostra erro', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    await ana.pagina.goto('/');

    const enviosGrupo: string[] = [];
    ana.pagina.on('request', (pedido) => {
        if (pedido.method() === 'POST' && new URL(pedido.url()).pathname === '/grupos') {
            enviosGrupo.push(pedido.url());
        }
    });

    await abrirModalCriarGrupo(ana.pagina);
    await ana.pagina.fill('#nome-grupo', 'rascunho de grupo');
    await ana.pagina.keyboard.press('Escape');
    await expect(ana.pagina.locator('#modal-criar-grupo')).toBeHidden();
    await expect(ana.pagina.locator('#abrir-criar-grupo')).toBeFocused();
    expect(enviosGrupo, 'Escape não envia o formulário').toHaveLength(0);

    await abrirModalCriarGrupo(ana.pagina);
    await expect(ana.pagina.locator('#nome-grupo')).toHaveValue('rascunho de grupo');
    await ana.pagina.getByRole('link', { name: 'Cancelar' }).click();
    await expect(ana.pagina.locator('#modal-criar-grupo')).toBeHidden();
    expect(enviosGrupo, 'Cancelar não envia o formulário').toHaveLength(0);

    await abrirModalCriarGrupo(ana.pagina);
    await ana.pagina.locator('#modal-criar-grupo button[type="submit"]').click();
    await ana.pagina.waitForLoadState('domcontentloaded');
    await expect(ana.pagina.locator('#modal-criar-grupo')).toBeVisible();
    await expect(ana.pagina.locator('#erro-membros-grupo')).toContainText('pelo menos um participante');
    await expect(ana.pagina.locator('#nome-grupo')).toHaveValue('rascunho de grupo');
    expect(enviosGrupo).toHaveLength(1);

    await ana.contexto.close();
});

test('anexo válido, prévia e recusa de tipo inválido', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const bruno = await contextoAutenticado(browser, 'bruno.e2e@example.com');
    await ana.pagina.goto('/?contato=2');
    await bruno.pagina.goto('/?contato=1');

    const arquivo = path.join(os.tmpdir(), 'chat-e2e-anexo.png');
    escreverPngSolido(arquivo, 320, 200);

    await ana.pagina.setInputFiles('#campo-anexo', arquivo);
    await expect(ana.pagina.locator('#pre-visualizacao-anexo img')).toBeVisible();
    const soImagem = ana.pagina.waitForResponse((res) => res.url().includes('/mensagens') && res.request().method() === 'POST');
    await ana.pagina.click('button[type="submit"][aria-label="Enviar"]');
    await soImagem;
    const itemSoImagem = ana.pagina.locator('#lista-mensagens li').filter({ has: ana.pagina.locator('.anexo-mensagem') }).last();
    await expect(itemSoImagem.locator('.anexo-mensagem img')).toBeVisible({ timeout: 15_000 });
    await assertLayoutAnexo(itemSoImagem);
    expect(await estruturaDoItem(itemSoImagem)).toEqual([
        'a.anexo-mensagem',
        'time.mensagem-horario',
        'div.acoes-mensagem',
    ]);

    await ana.pagina.fill('#campo-conteudo', 'foto com legenda');
    await ana.pagina.setInputFiles('#campo-anexo', arquivo);
    const comTexto = ana.pagina.waitForResponse((res) => res.url().includes('/mensagens') && res.request().method() === 'POST');
    await ana.pagina.click('button[type="submit"][aria-label="Enviar"]');
    await comTexto;
    const itemComTexto = ana.pagina.locator('#lista-mensagens li', { hasText: 'foto com legenda' });
    await assertLayoutAnexo(itemComTexto, { comTexto: true });
    expect(await estruturaDoCorpo(ana.pagina, 'foto com legenda')).toEqual([
        'p',
        'a.anexo-mensagem',
        'time.mensagem-horario',
        'div.acoes-mensagem',
    ]);

    await bruno.pagina.setInputFiles('#campo-anexo', arquivo);
    const recebida = bruno.pagina.waitForResponse((res) => res.url().includes('/mensagens') && res.request().method() === 'POST');
    await bruno.pagina.click('button[type="submit"][aria-label="Enviar"]');
    await recebida;
    const itemRecebida = ana.pagina.locator('#lista-mensagens li.sent').filter({ has: ana.pagina.locator('.anexo-mensagem') }).last();
    await expect(itemRecebida.locator('.anexo-mensagem img')).toBeVisible({ timeout: 15_000 });
    await assertLayoutAnexo(itemRecebida);

    await ana.pagina.locator('.messages').evaluate((area) => {
        area.scrollTop = area.scrollHeight;
    });
    await itemRecebida.scrollIntoViewIfNeeded();
    await ana.pagina.screenshot({ path: path.join(capturas, 'anexo.png'), fullPage: true });

    await ana.pagina.reload();
    await expect(ana.pagina.locator('#lista-mensagens li', { hasText: 'foto com legenda' })).toBeVisible();
    await assertLayoutAnexo(ana.pagina.locator('#lista-mensagens li').filter({ has: ana.pagina.locator('.anexo-mensagem') }).first());
    await assertLayoutAnexo(ana.pagina.locator('#lista-mensagens li', { hasText: 'foto com legenda' }), { comTexto: true });
    expect(await estruturaDoCorpo(ana.pagina, 'foto com legenda')).toEqual([
        'p',
        'a.anexo-mensagem',
        'time.mensagem-horario',
        'div.acoes-mensagem',
    ]);
    await assertLayoutAnexo(ana.pagina.locator('#lista-mensagens li.sent').filter({ has: ana.pagina.locator('.anexo-mensagem') }).last());

    await ana.pagina.locator('.messages').evaluate((area) => {
        area.scrollTop = area.scrollHeight;
    });
    await ana.pagina.setViewportSize({ width: 734, height: 800 });
    await ana.pagina.locator('.messages').evaluate((area) => {
        area.scrollTop = area.scrollHeight;
    });
    await assertLayoutAnexo(ana.pagina.locator('#lista-mensagens li', { hasText: 'foto com legenda' }), { comTexto: true });
    await ana.pagina.screenshot({ path: path.join(capturas, 'anexo-celular.png') });

    await ana.pagina.locator('#sidebar-toggle').click();
    await expect(ana.pagina.locator('#frame')).toHaveClass(/sidebar-expanded/);
    await assertLayoutAnexo(ana.pagina.locator('#lista-mensagens li', { hasText: 'foto com legenda' }), { comTexto: true });

    await ana.contexto.close();
    await bruno.contexto.close();
});

test('bloquear e desbloquear sem interromper outro contato', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    await ana.pagina.goto('/?contato=3');
    await ana.pagina.locator('.formulario-bloqueio button').click();
    await expect(ana.pagina.locator('.aviso-status')).toBeVisible();
    await expect(ana.pagina.locator('.aviso-bloqueio')).toContainText('grupos compartilhados');
    await expect(ana.pagina.getByRole('button', { name: 'Desbloquear' })).toBeVisible();

    const cabecalho = await ana.pagina.locator('.contact-profile').boundingBox();
    const avisoSessao = await ana.pagina.locator('.aviso-status').boundingBox();
    const avisoBloqueio = await ana.pagina.locator('.aviso-bloqueio').boundingBox();
    const compositor = await ana.pagina.locator('.message-input').boundingBox();
    expect(cabecalho && avisoSessao && avisoBloqueio && compositor).toBeTruthy();
    await retangulosNaoSeSobrepoem(cabecalho!, avisoSessao!);
    await retangulosNaoSeSobrepoem(avisoSessao!, avisoBloqueio!);
    await retangulosNaoSeSobrepoem(avisoBloqueio!, compositor!);
    await retangulosNaoSeSobrepoem(cabecalho!, compositor!);

    await ana.pagina.goto('/?contato=2');
    await expect(ana.pagina.locator('#campo-conteudo')).toBeEnabled();
    await enviarTexto(ana.pagina, 'bruno continua');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('bruno continua');

    await ana.pagina.goto('/profile');
    await expect(ana.pagina.locator('h2', { hasText: 'Contatos bloqueados' })).toBeVisible();
    await expect(ana.pagina.getByRole('heading', { name: 'Perfil', exact: true })).toBeVisible();
    await expect(ana.pagina.getByRole('link', { name: 'Chat' })).toBeVisible();
    await ana.pagina.getByRole('button', { name: 'Desbloquear' }).click();

    await ana.pagina.screenshot({ path: path.join(capturas, 'bloqueio.png'), fullPage: true });
    await ana.contexto.close();
});

test('desktop, celular e transição dos breakpoints', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    await ana.pagina.setViewportSize({ width: 1100, height: 800 });
    await ana.pagina.goto('/?contato=2');
    await expect(ana.pagina.locator('#contacts')).toBeVisible();
    await expect(ana.pagina.locator('#sidebar-toggle')).toBeHidden();
    await expect(ana.pagina.locator('#campo-conteudo')).toBeVisible();
    await expect(ana.pagina.locator('#campo-conteudo')).toBeEnabled();
    await ana.pagina.screenshot({ path: path.join(capturas, 'desktop.png') });

    await ana.pagina.setViewportSize({ width: 736, height: 800 });
    await expect(ana.pagina.locator('#sidebar-toggle')).toBeHidden();
    await expect(ana.pagina.locator('#campo-conteudo')).toBeEnabled();

    await ana.pagina.setViewportSize({ width: 734, height: 800 });
    await expect(ana.pagina.locator('#sidebar-toggle')).toBeVisible();
    await expect(ana.pagina.locator('#campo-conteudo')).toBeVisible();
    await ana.pagina.locator('#sidebar-toggle').click();
    await expect(ana.pagina.locator('#frame')).toHaveClass(/sidebar-expanded/);
    await ana.pagina.screenshot({ path: path.join(capturas, 'celular-gaveta.png') });
    await ana.pagina.locator('#sidebar-backdrop').click();
    await expect(ana.pagina.locator('#frame')).not.toHaveClass(/sidebar-expanded/);
    await expect(ana.pagina.locator('#campo-conteudo')).toBeEnabled();
    await expect(ana.pagina.locator('button[type="submit"][aria-label="Enviar"]')).toBeVisible();

    await ana.pagina.setViewportSize({ width: 900, height: 800 });
    await expect(ana.pagina.locator('#campo-conteudo')).toBeEnabled();
    await expect(ana.pagina.locator('#sidebar-toggle')).toBeHidden();

    await ana.pagina.setViewportSize({ width: 899, height: 800 });
    await expect(ana.pagina.locator('#campo-conteudo')).toBeEnabled();

    await ana.pagina.setViewportSize({ width: 1100, height: 800 });
    await expect(ana.pagina.locator('#frame')).not.toHaveClass(/sidebar-expanded/);
    await expect(ana.pagina.locator('#sidebar-toggle')).toBeHidden();
    await expect(ana.pagina.locator('#campo-conteudo')).toBeVisible();

    await ana.contexto.close();
});

test('envio pelo formulário com JavaScript desativado', async ({ browser }) => {
    const contexto = await browser.newContext({ javaScriptEnabled: false });
    const pagina = await contexto.newPage();
    await entrar(pagina, 'ana.e2e@example.com');
    await pagina.goto('/?contato=4');
    await pagina.fill('#campo-conteudo', 'envio sem javascript');
    const post = pagina.waitForResponse((res) => res.request().method() === 'POST' && res.url().includes('/mensagens'));
    await pagina.click('button[type="submit"][aria-label="Enviar"]');
    const resposta = await post;
    expect([200, 302]).toContain(resposta.status());
    await pagina.waitForLoadState('domcontentloaded');
    await expect(pagina.locator('#lista-mensagens')).toContainText('envio sem javascript');
    await contexto.close();
});

test('criação de grupo sem JavaScript usa o formulário do modal', async ({ browser }) => {
    const contexto = await browser.newContext({ javaScriptEnabled: false });
    const pagina = await contexto.newPage();
    await entrar(pagina, 'ana.e2e@example.com');
    await pagina.goto('/');
    await pagina.locator('#abrir-criar-grupo').click();
    await pagina.waitForURL(/criar_grupo=1/);
    await expect(pagina.locator('#modal-criar-grupo')).toBeVisible();
    await expect(pagina.getByRole('heading', { name: 'Criar grupo' })).toBeVisible();
    await pagina.fill('#nome-grupo', 'Grupo Sem JS');
    await pagina.locator('#modal-criar-grupo input[type="checkbox"]').nth(0).check();
    await pagina.locator('#modal-criar-grupo input[type="checkbox"]').nth(1).check();
    await pagina.locator('#modal-criar-grupo button[type="submit"]').click();
    await pagina.waitForLoadState('domcontentloaded');
    await expect(pagina.locator('.contact-profile')).toContainText('Grupo Sem JS');
    await expect(pagina).not.toHaveURL(/criar_grupo=1/);
    await contexto.close();
});

test('remoção sem JavaScript confirma no servidor, cancela e exclui uma vez', async ({ browser }) => {
    const contexto = await browser.newContext({ javaScriptEnabled: false });
    const pagina = await contexto.newPage();
    await entrar(pagina, 'ana.e2e@example.com');
    await pagina.goto('/?contato=4');
    await pagina.fill('#campo-conteudo', 'mensagem sem js para remover');
    const post = pagina.waitForResponse((res) => res.request().method() === 'POST' && res.url().includes('/mensagens'));
    await pagina.click('button[type="submit"][aria-label="Enviar"]');
    expect([200, 302]).toContain((await post).status());
    await pagina.waitForLoadState('domcontentloaded');
    await expect(pagina.locator('#lista-mensagens')).toContainText('mensagem sem js para remover');

    const exclusoes: string[] = [];
    pagina.on('request', (pedido) => {
        const caminho = new URL(pedido.url()).pathname;
        if (/^\/mensagens\/\d+$/.test(caminho) && pedido.method() !== 'GET') {
            exclusoes.push(`${pedido.method()} ${caminho}`);
        }
    });

    await pagina.locator('#lista-mensagens li', { hasText: 'mensagem sem js para remover' }).locator('.remover-mensagem').click();
    await pagina.waitForURL(/\/mensagens\/\d+\/confirmacao-remocao/);
    await expect(pagina.getByRole('heading', { name: 'Confirmar remoção' })).toBeVisible();
    await expect(pagina.getByText('Esta mensagem será removida para os participantes da conversa.')).toBeVisible();
    await expect(pagina.getByText('mensagem sem js para remover')).toBeVisible();
    await expect(pagina.getByRole('button', { name: 'Confirmar remoção' })).toBeVisible();
    expect(exclusoes, 'abrir a confirmação não envia DELETE').toHaveLength(0);

    await pagina.getByRole('link', { name: 'Cancelar' }).click();
    await pagina.waitForLoadState('domcontentloaded');
    await expect(pagina.locator('#lista-mensagens')).toContainText('mensagem sem js para remover');
    await expect(pagina.locator('#lista-mensagens')).not.toContainText('Mensagem removida');
    expect(exclusoes, 'cancelar não envia DELETE').toHaveLength(0);

    await pagina.locator('#lista-mensagens li', { hasText: 'mensagem sem js para remover' }).locator('.remover-mensagem').click();
    await pagina.waitForURL(/\/mensagens\/\d+\/confirmacao-remocao/);
    await pagina.screenshot({ path: path.join(capturas, 'confirmacao-remocao.png') });
    await pagina.getByRole('button', { name: 'Confirmar remoção' }).click();
    await pagina.waitForLoadState('domcontentloaded');
    await expect(pagina.locator('#lista-mensagens')).toContainText('Mensagem removida');
    await expect(pagina.locator('#lista-mensagens')).not.toContainText('mensagem sem js para remover');
    expect(exclusoes).toHaveLength(1);

    await contexto.close();
});
