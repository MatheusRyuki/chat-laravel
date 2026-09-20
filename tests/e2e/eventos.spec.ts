import { test, expect } from './fixtures';
import { entrar } from './helpers';

// Transporte controlado para testar duplicação, atraso e falhas de eventos.
// Os cenários de tempo-real.spec.ts continuam usando o Pusher real.
test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
        const eventos = {};
        window.__eventosTeste = eventos;
        const canal = {
            subscribed(fn) { eventos.assinatura = fn; return this; },
            listen(nome, fn) { eventos[nome] = fn; return this; },
            here(fn) { eventos.presentes = fn; return this; },
            joining(fn) { eventos.entrou = fn; return this; },
            leaving(fn) { eventos.saiu = fn; return this; },
            error(fn) { eventos.erroPresenca = fn; return this; },
        };
        window.Echo = {
            private: () => canal, join: () => canal,
            leave() {}, disconnect() { eventos.disconnected?.(); },
            connector: { pusher: {
                connection: { state: 'connected', bind(nome, fn) { eventos[nome] = fn; } },
                disconnect() { eventos.disconnected?.(); },
                connect() { eventos.connected?.(); },
            } },
        };
    });
    await entrar(page, 'ana.e2e@example.com');
    await page.goto('/?contato=2');
});

test('eventos duplicados e versões atrasadas não duplicam nem revertem mensagens', async ({ page }) => {
    const conversa = Number(await page.locator('#frame').getAttribute('data-conversa-id'));
    const mensagem = { id: 900001, conversa_id: conversa, remetente_id: 2, destinatario_id: 1, remetente_nome: 'Bruno E2E', conteudo: 'Versão atual', versao: 900001, created_at: '2026-09-20T12:00:00Z' };
    await page.evaluate((payload) => {
        window.__eventosTeste['.mensagem.enviada'](payload);
        window.__eventosTeste['.mensagem.enviada'](payload);
        window.__eventosTeste['.mensagem.alterada']({ ...payload, conteudo: 'Versão antiga', versao: 900000, editada: true });
    }, mensagem);
    const item = page.locator('[data-mensagem-id="900001"]');
    await expect(item).toHaveCount(1);
    await expect(item).toContainText('Versão atual');
    await expect(item).not.toContainText('Versão antiga');
    await expect(page.locator('#contacts .contact[data-contato-id="2"] .previa')).toHaveText('Versão atual');
    await expect(page.locator('#anuncio-mensagens')).toContainText('Bruno E2E');
    await page.evaluate((payload) => window.__eventosTeste['.mensagem.alterada']({ ...payload, conteudo: '', removida: true, versao: 900002 }), mensagem);
    await expect(item).toContainText('Mensagem removida');
    await expect(item.locator('.acoes-mensagem')).toHaveCount(0);
});

test('presença distingue entrada, saída e indisponibilidade do transporte', async ({ page }) => {
    const presenca = page.locator('#contacts [data-presenca-usuario="2"]');
    await page.evaluate(() => window.__eventosTeste.presentes([{ id: 1 }, { id: 2 }]));
    await expect(presenca).toHaveClass(/online/);
    await page.evaluate(() => window.__eventosTeste.saiu({ id: 2 }));
    await expect(presenca).toHaveClass(/offline/);
    await page.evaluate(() => window.__eventosTeste.entrou({ id: 2 }));
    await expect(presenca).toHaveClass(/online/);
    await page.evaluate(() => window.__eventosTeste.erroPresenca());
    await expect(presenca).toHaveClass(/indisponivel/);
    await page.evaluate(() => window.__eventosTeste.entrou({ id: 2 }));
    await expect(presenca).toHaveClass(/indisponivel/);
    await page.evaluate(() => window.__eventosTeste.presentes([{ id: 2 }]));
    await expect(presenca).toHaveClass(/online/);
});

test('vários participantes digitando e parada explícita atualizam o indicador', async ({ page }) => {
    const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const resposta = await page.request.post('/grupos', { data: { nome: 'Digitadores', membros: [2, 3] }, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token! } });
    expect(resposta.status()).toBe(201);
    const conversa = (await resposta.json()).conversa_id;
    await page.goto(`/?grupo=${conversa}`);
    await page.evaluate((id) => {
        window.__eventosTeste['.participante.digitando']({ conversa_id: id, usuario_id: 2, nome: 'Bruno', digitando: true });
        window.__eventosTeste['.participante.digitando']({ conversa_id: id, usuario_id: 3, nome: 'Carla', digitando: true });
    }, conversa);
    await expect(page.locator('#indicador-digitacao')).toHaveText('Bruno, Carla estão digitando…');
    await page.evaluate((id) => window.__eventosTeste['.participante.digitando']({ conversa_id: id, usuario_id: 2, digitando: false }), conversa);
    await expect(page.locator('#indicador-digitacao')).toHaveText('Carla está digitando…');
    await page.evaluate((id) => window.__eventosTeste['.participante.digitando']({ conversa_id: id, usuario_id: 3, digitando: false }), conversa);
    await expect(page.locator('#indicador-digitacao')).toBeHidden();
});

test('navegação por contato preserva rascunho e restaura o foco da gaveta', async ({ page }) => {
    await page.locator('#campo-conteudo').fill('Rascunho antes da navegação');
    await page.locator('#contacts a[href$="contato=3"]').click();
    await expect(page).toHaveURL(/contato=3/);
    await page.locator('#contacts a[href$="contato=2"]').click();
    await expect(page.locator('#campo-conteudo')).toHaveValue('Rascunho antes da navegação');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('#sidebar-toggle').click();
    await page.keyboard.press('Escape');
    await expect(page.locator('#sidebar-toggle')).toBeFocused();
    await page.locator('#sidebar-toggle').click();
    await page.locator('#abrir-criar-grupo').click();
    await expect(page.locator('#nome-grupo')).toBeFocused();
    await page.locator('#modal-criar-grupo [data-fechar-dialogo]').first().click();
    await expect(page.locator('#frame')).toHaveClass(/sidebar-expanded/);
    await expect(page.locator('#abrir-criar-grupo')).toBeFocused();
});

test('evento de leitura inválido e logout antigo não encerram a sessão', async ({ page }) => {
    await page.evaluate(() => {
        window.dispatchEvent(new StorageEvent('storage', { key: 'chat-leitura:1', newValue: '{invalido' }));
        window.dispatchEvent(new StorageEvent('storage', { key: 'chat-sessao-encerrada', newValue: '1' }));
        window.__eventosTeste['.diagnostico.pusher']({ mensagem: 'Diagnóstico controlado', usuario_id: 1 });
    });
    await expect(page.locator('#campo-conteudo')).toBeEnabled();
    await expect(page).toHaveURL(/contato=2/);
    expect(await page.evaluate(() => window.__chatDiagnostico.eventosPusher.some((evento) => evento.nome === 'diagnostico.pusher'))).toBe(true);
});


test('repetição do evento não aumenta o contador de não lidas', async ({ page }) => {
    const item = page.locator('#contacts .contact[data-contato-id="3"]');
    const antes = Number(await item.locator('.nao-lidas').textContent());
    await page.evaluate(() => {
        const payload = { id: 900010, conversa_id: 900010, remetente_id: 3, destinatario_id: 1, conteudo: 'Evento repetido', versao: 1, created_at: '2026-09-20T12:00:00Z' };
        window.__eventosTeste['.mensagem.enviada'](payload);
        window.__eventosTeste['.mensagem.enviada'](payload);
    });
    await expect(item.locator('.nao-lidas')).toHaveText(String(antes + 1));
    await expect(item.locator('.previa')).toHaveText('Evento repetido');
});
