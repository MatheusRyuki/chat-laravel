import { test, expect } from './fixtures';
import { entrar, enviarTexto } from './helpers';

test('falhas de envio preservam rascunho e permitem tentar novamente', async ({ page }) => {
    await entrar(page, 'ana.e2e@example.com');
    await page.goto('/?contato=2');
    for (const [status, body, mensagem] of [
        [422, { errors: { conteudo: ['Texto recusado pelo servidor'] } }, 'Texto recusado'],
        [422, {}, 'Não foi possível enviar'],
        [401, {}, 'Sessão expirada'],
        [419, {}, 'Sessão expirada'],
        [403, {}, 'Verifique o acesso'],
        [500, {}, 'Não foi possível confirmar'],
    ] as const) {
        await page.route('**/mensagens', (route) => route.fulfill({ status, json: body }));
        await page.locator('#campo-conteudo').fill(`Rascunho ${status}`);
        await page.locator('button[aria-label=Enviar]').click();
        await expect(page.locator('#erro-envio')).toContainText(mensagem);
        await expect(page.locator('#campo-conteudo')).toHaveValue(`Rascunho ${status}`);
        await expect(page.locator('button[aria-label=Enviar]')).toBeEnabled();
        await page.unroute('**/mensagens');
    }
    await page.route('**/mensagens', (route) => route.abort('failed'));
    await page.locator('button[aria-label=Enviar]').click();
    await expect(page.locator('#erro-envio')).toContainText('Falha de rede');
    await expect(page.locator('#campo-conteudo')).toHaveValue('Rascunho 500');
    await page.unroute('**/mensagens');
    await enviarTexto(page, 'Envio recuperado após falhas');
    await expect(page.locator('#campo-conteudo')).toHaveValue('');
    await expect(page.locator('#erro-envio')).toBeHidden();
    await expect(page.locator('#lista-mensagens')).toContainText('Envio recuperado após falhas');
});

test('resposta atrasada não apaga o próximo rascunho nem duplica o envio', async ({ page }) => {
    await entrar(page, 'ana.e2e@example.com');
    await page.goto('/?contato=2');
    let liberar!: () => void;
    const espera = new Promise<void>((resolve) => { liberar = resolve; });
    let pedidos = 0;
    await page.route('**/mensagens', async (route) => {
        pedidos++;
        const resposta = await route.fetch();
        await espera;
        await route.fulfill({ response: resposta });
    });
    await page.locator('#campo-conteudo').fill('Mensagem com resposta atrasada');
    await page.locator('button[aria-label=Enviar]').click();
    await expect(page.locator('button[aria-label=Enviar]')).toBeDisabled();
    await page.locator('#campo-conteudo').fill('Próximo rascunho');
    await page.locator('#formulario-mensagem').evaluate((form: HTMLFormElement) => form.requestSubmit());
    liberar();
    await expect(page.locator('button[aria-label=Enviar]')).toBeEnabled();
    expect(pedidos).toBe(1);
    await expect(page.locator('#campo-conteudo')).toHaveValue('Próximo rascunho');
    await page.reload();
    await expect(page.locator('#campo-conteudo')).toHaveValue('Próximo rascunho');
});

test('composição IME e Enter no celular não enviam antes da confirmação', async ({ page }) => {
    await entrar(page, 'ana.e2e@example.com');
    await page.goto('/?contato=2');
    const campo = page.locator('#campo-conteudo');
    let envios = 0;
    page.on('request', (request) => { if (new URL(request.url()).pathname === '/mensagens' && request.method() === 'POST') envios++; });
    await campo.fill('日本語');
    await campo.dispatchEvent('compositionstart');
    await campo.press('Enter');
    await expect(campo).toHaveValue('日本語\n');
    expect(envios).toBe(0);
    await campo.dispatchEvent('compositionend');
    await campo.press('Enter');
    await expect(campo).toHaveValue('');
    expect(envios).toBe(1);
    await page.setViewportSize({ width: 390, height: 844 });
    await campo.fill('Texto no celular');
    await campo.press('Enter');
    await expect(campo).toHaveValue('Texto no celular\n');
    expect(envios).toBe(1);
});

test('erro de histórico, edição e remoção mantém mensagens e oferece recuperação', async ({ page }) => {
    await entrar(page, 'ana.e2e@example.com');
    await page.goto('/?contato=2');
    await page.route('**/mensagens?**antes_id**', (route) => route.fulfill({ status: 503, json: {} }));
    await page.locator('#carregar-anteriores').click();
    await expect(page.locator('#aviso-sincronizacao')).toBeVisible();
    await expect(page.locator('#carregar-anteriores')).not.toHaveAttribute('aria-busy', 'true');
    await page.unroute('**/mensagens?**antes_id**');
    await enviarTexto(page, 'Preservada quando alteração falha');
    const item = page.locator('#lista-mensagens li', { hasText: 'Preservada quando alteração falha' });
    await page.route('**/mensagens/*', (route) => route.fulfill({ status: 503, json: {} }));
    page.once('dialog', (dialog) => dialog.dismiss());
    await item.locator('.editar-mensagem').click();
    await expect(item).toContainText('Preservada quando alteração falha');
    page.once('dialog', (dialog) => dialog.accept('Novo texto'));
    await item.locator('.editar-mensagem').click();
    await expect(page.locator('#erro-envio')).toContainText('Não foi possível editar');
    page.once('dialog', (dialog) => dialog.accept());
    await item.locator('.remover-mensagem').click();
    await expect(page.locator('#erro-envio')).toContainText('Não foi possível remover');
    await expect(item).toContainText('Preservada quando alteração falha');
    await page.unroute('**/mensagens/*');
});

test('logout encerra outra aba e limpa os rascunhos da conta', async ({ page, context }) => {
    await entrar(page, 'ana.e2e@example.com');
    await page.goto('/?contato=2');
    await page.locator('#campo-conteudo').fill('Rascunho privado');
    const outra = await context.newPage();
    await outra.goto('/?contato=3');
    await outra.locator('#campo-conteudo').fill('Outro rascunho privado');
    await page.locator('#formulario-sair button').click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(outra).toHaveURL(/\/login$/);
    for (const aba of [page, outra]) {
        expect(await aba.evaluate(() => Object.keys(sessionStorage).filter((chave) => chave.startsWith('chat-rascunho:')))).toEqual([]);
    }
});

test('armazenamento e BroadcastChannel indisponíveis não impedem envio e logout', async ({ page }) => {
    await entrar(page, 'ana.e2e@example.com');
    await page.addInitScript(() => {
        for (const method of ['getItem', 'setItem', 'removeItem', 'key']) {
            Storage.prototype[method] = () => { throw new DOMException('Bloqueado', 'SecurityError'); };
        }
        window.BroadcastChannel = class { constructor() { throw new Error('Indisponível'); } } as typeof BroadcastChannel;
    });
    await page.goto('/?contato=2');
    await enviarTexto(page, 'Enviada sem armazenamento local');
    await expect(page.locator('#lista-mensagens')).toContainText('Enviada sem armazenamento local');
    await page.locator('#formulario-sair button').click();
    await expect(page).toHaveURL(/\/login$/);
});
