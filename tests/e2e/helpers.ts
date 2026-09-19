import { expect, type Browser, type Page, type Request, type Response } from '@playwright/test';

export const senha = 'password';
export const capturas = 'docs/screenshots';

export type EventoDiagnostico = {
    nome: string;
    t: number;
    id: number | null;
    conversa_id: number | null;
    conteudo: string | null;
    versao: number | null;
    removida: boolean;
    editada: boolean;
    usuario_id: number | null;
    acao: string | null;
    digitando?: boolean;
    nome_participante?: string | null;
};

export type DiagnosticoCliente = {
    prefixoCanal: string;
    usuarioId: number | null;
    conversaId: number | null;
    canal: string;
    estado: string;
    asinado: boolean;
    eventosPusher: EventoDiagnostico[];
    reconciliacoes: { origem: string; t: number; versao: number }[];
};

export type RegistroHttp = {
    t: number;
    metodo: string;
    url: string;
    status?: number;
    duracaoMs?: number;
};

export async function entrar(page: Page, email: string) {
    await page.goto('/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', senha);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => url.pathname === '/' || url.pathname === '');
}

export async function contextoAutenticado(browser: Browser, email: string) {
    const contexto = await browser.newContext();
    const pagina = await contexto.newPage();
    await entrar(pagina, email);

    return { contexto, pagina };
}

export function monitorarHttp(pagina: Page): RegistroHttp[] {
    const registros: RegistroHttp[] = [];
    const inicioPorPedido = new Map<Request, number>();

    pagina.on('request', (pedido: Request) => {
        const url = pedido.url();

        if (! /\/(mensagens|broadcasting\/auth|leituras|digitacao|e2e\/)/.test(url)) {
            return;
        }

        inicioPorPedido.set(pedido, Date.now());
        registros.push({
            t: Date.now(),
            metodo: pedido.method(),
            url,
        });
    });

    pagina.on('response', (resposta: Response) => {
        const url = resposta.url();

        if (! /\/(mensagens|broadcasting\/auth|leituras|digitacao|e2e\/)/.test(url)) {
            return;
        }

        const inicio = inicioPorPedido.get(resposta.request());
        registros.push({
            t: Date.now(),
            metodo: resposta.request().method(),
            url,
            status: resposta.status(),
            duracaoMs: inicio ? Date.now() - inicio : undefined,
        });
    });

    return registros;
}

export async function diagnosticoCliente(pagina: Page): Promise<DiagnosticoCliente> {
    return pagina.evaluate(() => window.__chatDiagnostico);
}

export async function aguardarCanalPrivado(pagina: Page) {
    await pagina.waitForFunction(
        () => window.__chatDiagnostico?.assinado === true
            && (window.__chatDiagnostico?.estado === 'connected' || window.Echo?.connector?.pusher?.connection?.state === 'connected'),
        null,
        { timeout: 25_000 },
    );
}

export async function enviarTexto(pagina: Page, texto: string) {
    await pagina.fill('#campo-conteudo', texto);
    const pendente = pagina.waitForResponse((res) => res.url().includes('/mensagens') && res.request().method() === 'POST');
    await pagina.click('button[type="submit"][aria-label="Enviar"]');
    const resposta = await pendente;
    expect(resposta.status(), await resposta.text()).toBe(201);
}

export async function eventoPusherComConteudo(pagina: Page, nome: string, trecho: string, aPartirDe = 0) {
    await pagina.waitForFunction(
        ({ nomeEvento, texto, inicio }) => window.__chatDiagnostico?.eventosPusher
            ?.slice(inicio)
            .some((evento) => evento.nome === nomeEvento && String(evento.conteudo || '').includes(texto)),
        { nomeEvento: nome, texto: trecho, inicio: aPartirDe },
        { timeout: 20_000 },
    );
}

export function itemContato(pagina: Page, nome: string) {
    return pagina.locator('#contacts .contact', { hasText: nome });
}
