import { expect, type Browser, type Locator, type Page, type Request, type Response } from '@playwright/test';
import { deflateSync } from 'node:zlib';
import { writeFileSync } from 'node:fs';

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

export async function esgotarHistoricoAnterior(pagina: Page) {
    const botao = pagina.locator('#carregar-anteriores');

    for (let tentativa = 0; tentativa < 6; tentativa += 1) {
        if (! await botao.isVisible()) {
            break;
        }

        const lote = pagina.waitForResponse((resposta) => resposta.url().includes('/mensagens')
            && resposta.url().includes('antes_id')
            && resposta.request().method() === 'GET');
        await botao.click();
        await lote;
    }

    await expect(botao).toBeHidden();
}

export async function textosDoHistorico(pagina: Page): Promise<string[]> {
    return pagina.locator('#lista-mensagens li[data-mensagem-id] .mensagem-corpo > p').evaluateAll(
        (paragrafos) => paragrafos.map((paragrafo) => paragrafo.textContent?.trim() ?? ''),
    );
}

export async function estruturaDoCorpo(pagina: Page, trecho: string): Promise<string[]> {
    return estruturaDoItem(pagina.locator('#lista-mensagens li', { hasText: trecho }).first());
}

export async function estruturaDoItem(item: Locator): Promise<string[]> {
    return item.locator('.mensagem-corpo').evaluate(
        (corpo) => [...corpo.children].map((elemento) => {
            const classe = elemento.className.toString().trim().split(/\s+/)[0] || '';

            return classe ? `${elemento.tagName.toLowerCase()}.${classe}` : elemento.tagName.toLowerCase();
        }),
    );
}

export async function retangulosNaoSeSobrepoem(primeiro: { x: number; y: number; width: number; height: number }, segundo: { x: number; y: number; width: number; height: number }): Promise<void> {
    const sobrepoe = primeiro.x < segundo.x + segundo.width
        && primeiro.x + primeiro.width > segundo.x
        && primeiro.y < segundo.y + segundo.height
        && primeiro.y + primeiro.height > segundo.y;

    expect(sobrepoe, 'blocos da coluna não devem se sobrepor').toBeFalsy();
}

function crc32(buffer: Buffer): number {
    let crc = 0xffffffff;

    for (const byte of buffer) {
        crc ^= byte;

        for (let bit = 0; bit < 8; bit++) {
            crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
        }
    }

    return (crc ^ 0xffffffff) >>> 0;
}

function chunkPng(tipo: string, dados: Buffer): Buffer {
    const tipoBuf = Buffer.from(tipo);
    const comprimento = Buffer.alloc(4);
    comprimento.writeUInt32BE(dados.length);
    const crcBuf = Buffer.alloc(4);
    crcBuf.writeUInt32BE(crc32(Buffer.concat([tipoBuf, dados])));

    return Buffer.concat([comprimento, tipoBuf, dados, crcBuf]);
}

export function escreverPngSolido(caminho: string, largura: number, altura: number, rgb: [number, number, number] = [37, 99, 235]): void {
    const bytesPorLinha = largura * 3 + 1;
    const raw = Buffer.alloc(bytesPorLinha * altura);

    for (let y = 0; y < altura; y++) {
        const inicio = y * bytesPorLinha;
        raw[inicio] = 0;

        for (let x = 0; x < largura; x++) {
            const i = inicio + 1 + x * 3;
            raw[i] = rgb[0];
            raw[i + 1] = rgb[1];
            raw[i + 2] = rgb[2];
        }
    }

    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(largura, 0);
    ihdr.writeUInt32BE(altura, 4);
    ihdr[8] = 8;
    ihdr[9] = 2;

    writeFileSync(caminho, Buffer.concat([
        Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
        chunkPng('IHDR', ihdr),
        chunkPng('IDAT', deflateSync(raw)),
        chunkPng('IEND', Buffer.alloc(0)),
    ]));
}

export async function assertLayoutAnexo(item: Locator, opcoes: { comTexto?: boolean } = {}): Promise<void> {
    const imagem = item.locator('.anexo-mensagem img');
    await expect(imagem).toBeVisible();
    await imagem.evaluate((elemento: HTMLImageElement) => (elemento.complete ? Promise.resolve() : new Promise<void>((resolver, rejeitar) => {
        elemento.addEventListener('load', () => resolver(), { once: true });
        elemento.addEventListener('error', () => rejeitar(new Error('anexo não carregou')), { once: true });
    })));

    const caixaImagem = await imagem.boundingBox();
    expect(caixaImagem, 'anexo precisa de caixa visível').toBeTruthy();
    expect(caixaImagem!.width, 'anexo não deve herdar 22px do avatar').toBeGreaterThan(80);
    expect(caixaImagem!.height, 'anexo precisa de altura legível').toBeGreaterThan(50);

    const estilo = await imagem.evaluate((elemento) => {
        const computado = getComputedStyle(elemento);

        return { width: computado.width, float: computado.float, borderRadius: computado.borderRadius };
    });
    expect(estilo.float).toBe('none');
    expect(Number.parseFloat(estilo.width)).toBeGreaterThan(80);
    expect(estilo.borderRadius).not.toBe('50%');

    const paragrafos = item.locator('.mensagem-corpo > p');

    if (opcoes.comTexto) {
        await expect(paragrafos).toHaveCount(1);
        await expect(paragrafos).not.toHaveText(/^$/);
    } else {
        await expect(paragrafos).toHaveCount(0);
    }

    const caixaHorario = await item.locator('.mensagem-horario').boundingBox();
    expect(caixaHorario).toBeTruthy();
    await retangulosNaoSeSobrepoem(caixaImagem!, caixaHorario!);

    const acoes = item.locator('.acoes-mensagem');

    if (await acoes.count()) {
        const caixaAcoes = await acoes.boundingBox();
        expect(caixaAcoes).toBeTruthy();
        await retangulosNaoSeSobrepoem(caixaImagem!, caixaAcoes!);
        await retangulosNaoSeSobrepoem(caixaHorario!, caixaAcoes!);
    }

    const conversa = await item.page().locator('#frame .content .messages').boundingBox();
    expect(conversa).toBeTruthy();
    expect(caixaImagem!.x + caixaImagem!.width).toBeLessThanOrEqual(conversa!.x + conversa!.width + 1);
}

export async function assertModalCriarGrupo(pagina: Page): Promise<void> {
    const modal = pagina.locator('#modal-criar-grupo');
    await expect(modal).toBeVisible();
    await expect(modal.getByRole('heading', { name: 'Criar grupo' })).toBeVisible();
    await expect(modal.locator('#ajuda-grupo')).toHaveText('Novos membros podem consultar o histórico do grupo.');
    await expect(pagina.locator('#nome-grupo')).toBeFocused();
    await expect(modal.locator('.participante-grupo img').first()).toBeVisible();

    const caixa = await modal.boundingBox();
    expect(caixa).toBeTruthy();
    expect(caixa!.width).toBeLessThanOrEqual(496);
    expect(caixa!.width).toBeGreaterThan(240);
}

export async function abrirModalCriarGrupo(pagina: Page): Promise<void> {
    await pagina.locator('#abrir-criar-grupo').click();
    await assertModalCriarGrupo(pagina);
}

export async function assertBlocoAcoesSidebar(pagina: Page): Promise<void> {
    const barra = pagina.locator('#bottom-bar');
    const novoGrupo = pagina.locator('#abrir-criar-grupo');
    const conta = pagina.locator('#bottom-bar .link-conta');
    const sair = pagina.locator('#formulario-sair button');

    await expect(barra).toBeVisible();
    await expect(novoGrupo).toHaveText('Novo grupo');
    await expect(conta).toHaveText('Conta');
    await expect(sair).toHaveText('Sair');
    await expect(pagina.locator('#formulario-sair')).toHaveAttribute('method', /post/i);
    await expect(pagina.locator('#formulario-sair input[name="_token"]')).toHaveCount(1);

    const caixaBarra = await barra.boundingBox();
    const caixaNovo = await novoGrupo.boundingBox();
    const caixaConta = await conta.boundingBox();
    const caixaSair = await sair.boundingBox();
    const caixaPainel = await pagina.locator('#sidepanel').boundingBox();

    expect(caixaBarra && caixaNovo && caixaConta && caixaSair && caixaPainel).toBeTruthy();
    expect(caixaBarra!.x).toBeGreaterThanOrEqual(caixaPainel!.x - 1);
    expect(caixaBarra!.x + caixaBarra!.width).toBeLessThanOrEqual(caixaPainel!.x + caixaPainel!.width + 1);
    expect(caixaNovo!.width).toBeGreaterThan(caixaBarra!.width * 0.85);
    expect(Math.abs(caixaConta!.width - caixaSair!.width)).toBeLessThan(2);
    expect(Math.abs(caixaConta!.height - caixaSair!.height)).toBeLessThan(2);
    expect(Math.abs(caixaConta!.y - caixaSair!.y)).toBeLessThan(2);
    expect(Math.abs(caixaNovo!.height - caixaConta!.height)).toBeLessThan(2);
}
