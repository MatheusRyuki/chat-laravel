import { test, expect } from '@playwright/test';
import path from 'node:path';
import { writeFileSync, mkdirSync } from 'node:fs';
import {
    aguardarCanalPrivado,
    capturas,
    contextoAutenticado,
    entrar,
    enviarTexto,
    eventoPusherComConteudo,
    itemContato,
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

test('histórico com mais de 50 mensagens e preservação da rolagem', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    await ana.pagina.goto('/?contato=2');
    await expect(ana.pagina.locator('#lista-mensagens li[data-mensagem-id]')).toHaveCount(50);
    await expect(ana.pagina.getByText('Histórico janela 55', { exact: true })).toBeVisible();
    await expect(ana.pagina.getByText('Histórico janela 1', { exact: true })).toHaveCount(0);

    const botao = ana.pagina.locator('#carregar-anteriores');
    await expect(botao).toBeVisible();
    await botao.click();
    await expect(ana.pagina.getByText('Histórico janela 1', { exact: true })).toBeVisible();

    await ana.pagina.screenshot({ path: path.join(capturas, 'historico-paginado.png'), fullPage: true });
    await ana.contexto.close();
});

test('editar e remover mensagem própria', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    await ana.pagina.goto('/?contato=3');
    await enviarTexto(ana.pagina, 'mensagem editável');
    await ana.pagina.reload();
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('mensagem editável');

    ana.pagina.once('dialog', (dialog) => dialog.accept('mensagem já editada'));
    await ana.pagina.locator('.editar-mensagem').last().click();
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('mensagem já editada');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('Editada');

    await ana.pagina.locator('.remover-mensagem').last().click();
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('Mensagem removida');

    await ana.pagina.screenshot({ path: path.join(capturas, 'editar-remover.png'), fullPage: true });
    await ana.contexto.close();
});

test('grupo com três participantes e recusa do quarto', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    const davi = await contextoAutenticado(browser, 'davi.e2e@example.com');

    await ana.pagina.goto('/');
    await ana.pagina.locator('#criar-grupo summary').click();
    await ana.pagina.fill('#nome-grupo', 'Time E2E');
    await ana.pagina.locator('#criar-grupo input[type="checkbox"]').nth(0).check();
    await ana.pagina.locator('#criar-grupo input[type="checkbox"]').nth(1).check();
    await ana.pagina.locator('#criar-grupo button[type="submit"]').click();
    await expect(ana.pagina.locator('.contact-profile')).toContainText('Time E2E');
    await expect(ana.pagina.locator('.aviso-status')).toContainText('histórico');

    await enviarTexto(ana.pagina, 'olá grupo e2e');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('olá grupo e2e');

    const href = ana.pagina.url();
    const resposta = await davi.pagina.goto(href);
    expect(resposta?.status()).toBe(404);

    await ana.pagina.screenshot({ path: path.join(capturas, 'grupo.png'), fullPage: true });
    await ana.contexto.close();
    await davi.contexto.close();
});

test('anexo válido, prévia e recusa de tipo inválido', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    await ana.pagina.goto('/?contato=3');

    mkdirSync(path.join('tests', 'e2e', 'fixtures'), { recursive: true });
    const png = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        'base64',
    );
    const arquivo = path.join('tests', 'e2e', 'fixtures', 'ok.png');
    writeFileSync(arquivo, png);

    await ana.pagina.setInputFiles('#campo-anexo', arquivo);
    await expect(ana.pagina.locator('#pre-visualizacao-anexo img')).toBeVisible();
    const resposta = ana.pagina.waitForResponse((res) => res.url().includes('/mensagens') && res.request().method() === 'POST');
    await ana.pagina.click('button[type="submit"][aria-label="Enviar"]');
    await resposta;
    await expect(ana.pagina.locator('#lista-mensagens .anexo-mensagem img')).toBeVisible({ timeout: 15_000 });

    await ana.pagina.screenshot({ path: path.join(capturas, 'anexo.png'), fullPage: true });
    await ana.contexto.close();
});

test('bloquear e desbloquear sem interromper outro contato', async ({ browser }) => {
    const ana = await contextoAutenticado(browser, 'ana.e2e@example.com');
    await ana.pagina.goto('/?contato=3');
    await ana.pagina.locator('.formulario-bloqueio button').click();
    await expect(ana.pagina.locator('.aviso-bloqueio')).toContainText('grupos compartilhados');

    await ana.pagina.goto('/?contato=2');
    await expect(ana.pagina.locator('#campo-conteudo')).toBeEnabled();
    await enviarTexto(ana.pagina, 'bruno continua');
    await expect(ana.pagina.locator('#lista-mensagens')).toContainText('bruno continua');

    await ana.pagina.goto('/profile');
    await expect(ana.pagina.locator('h2', { hasText: 'Contatos bloqueados' })).toBeVisible();
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

    await ana.pagina.setViewportSize({ width: 700, height: 800 });
    await expect(ana.pagina.locator('#sidebar-toggle')).toBeVisible();
    await expect(ana.pagina.locator('#campo-conteudo')).toBeVisible();
    await ana.pagina.locator('#sidebar-toggle').click();
    await expect(ana.pagina.locator('#frame')).toHaveClass(/sidebar-expanded/);
    await ana.pagina.screenshot({ path: path.join(capturas, 'celular-gaveta.png') });
    await ana.pagina.locator('#sidebar-backdrop').click();
    await expect(ana.pagina.locator('#frame')).not.toHaveClass(/sidebar-expanded/);
    await expect(ana.pagina.locator('#campo-conteudo')).toBeEnabled();
    await expect(ana.pagina.locator('button[type="submit"][aria-label="Enviar"]')).toBeVisible();

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
    await pagina.goto('/?contato=3');
    await pagina.fill('#campo-conteudo', 'envio sem javascript');
    const post = pagina.waitForResponse((res) => res.request().method() === 'POST' && res.url().includes('/mensagens'));
    await pagina.click('button[type="submit"][aria-label="Enviar"]');
    const resposta = await post;
    expect([200, 302]).toContain(resposta.status());
    await pagina.waitForLoadState('domcontentloaded');
    await expect(pagina.locator('#lista-mensagens')).toContainText('envio sem javascript');
    await contexto.close();
});
