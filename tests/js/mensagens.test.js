import { test } from 'node:test';
import assert from 'node:assert/strict';
import { compararMensagens, formatarHorarioMensagem, previaDePayload } from '../../resources/js/mensagens.js';

test('ordena mensagens por data e usa id como desempate', () => {
    const a = { id: 2, created_at: '2026-01-01' };
    const b = { id: 3, created_at: '2026-01-01' };
    const c = { id: 1, created_at: '2026-01-02' };
    assert.deepEqual([c, b, a].sort(compararMensagens), [a, b, c]);
    assert.equal(compararMensagens(a, a), 0);
    assert.ok(compararMensagens(c, a) > 0);
});

test('horário usa o dia e ano no fuso da aplicação', () => {
    const agora = new Date('2026-09-20T01:00:00Z');
    assert.equal(formatarHorarioMensagem(null), '');
    assert.equal(formatarHorarioMensagem('invalido'), '');
    assert.equal(formatarHorarioMensagem('2026-09-20T00:30:00Z', 'America/Sao_Paulo', agora), '21:30');
    assert.equal(formatarHorarioMensagem('2026-09-19T01:00:00Z', 'America/Sao_Paulo', agora), '18/09, 22:00');
    assert.equal(formatarHorarioMensagem('2025-01-01T01:00:00Z', 'America/Sao_Paulo', agora), '31/12/2024, 22:00');
    assert.equal(formatarHorarioMensagem('2026-09-20T01:00:00Z', undefined, agora), '01:00');
});

for (const [nome, payload, esperado] of [
    ['removida', { removida: true, conteudo: 'segredo' }, 'Mensagem removida'],
    ['vazia', {}, 'Nenhuma mensagem ainda'],
    ['espaços', { conteudo: '  ' }, 'Nenhuma mensagem ainda'],
    ['texto', { conteudo: ' Oi ' }, 'Oi'],
    ['texto no limite', { conteudo: 'á'.repeat(80) }, 'á'.repeat(80)],
    ['texto longo', { conteudo: 'á'.repeat(81) }, `${'á'.repeat(77)}…`],
    ['imagem', { anexo_url: '/foto' }, 'Imagem'],
    ['legenda', { anexo_url: '/foto', conteudo: ' Oi ' }, 'Imagem · Oi'],
    ['legenda no limite', { anexo_url: '/foto', conteudo: 'é'.repeat(60) }, `Imagem · ${'é'.repeat(60)}`],
    ['legenda longa', { anexo_url: '/foto', conteudo: 'é'.repeat(61) }, `Imagem · ${'é'.repeat(57)}…`],
]) {
    test(`prévia: ${nome}`, () => assert.equal(previaDePayload(payload), esperado));
}
