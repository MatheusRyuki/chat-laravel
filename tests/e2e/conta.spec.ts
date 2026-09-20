import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

async function cadastrar(page: Page, email: string) {
    await page.goto('/register');
    await page.locator('#name').fill('Conta de teste');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('senha-inicial-segura');
    await page.locator('#password_confirmation').fill('senha-inicial-segura');
    await page.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/$/);
}

async function login(page: Page, email: string, senha: string) {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(senha);
    await page.locator('button[type=submit]').click();
}

test('cadastro valida confirmação e impede email duplicado', async ({ page }) => {
    const email = `cadastro-${Date.now()}@example.test`;
    await page.goto('/register');
    await page.locator('#name').fill('Cadastro');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('senha-inicial-segura');
    await page.locator('#password_confirmation').fill('senha-diferente');
    await page.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/register$/);
    await expect(page.locator('form ul')).toBeVisible();
    await cadastrar(page, email);
    await page.locator('#formulario-sair button').click();
    await page.goto('/register');
    await page.locator('#name').fill('Duplicada');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('senha-inicial-segura');
    await page.locator('#password_confirmation').fill('senha-inicial-segura');
    await page.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/register$/);
    await expect(page.locator('form ul')).toBeVisible();
});

test('perfil, troca de senha e exclusão exigem credenciais válidas', async ({ page }) => {
    const email = `perfil-${Date.now()}@example.test`;
    await cadastrar(page, email);
    await page.goto('/profile');
    const perfil = page.locator('form').filter({ has: page.locator('#name') });
    await perfil.locator('#name').fill('Nome atualizado');
    await perfil.locator('button[type=submit]').click();
    await page.reload();
    await expect(page.locator('#name')).toHaveValue('Nome atualizado');
    const senha = page.locator('form[action$="/password"]');
    await senha.locator('[name=current_password]').fill('errada');
    await senha.locator('[name=password]').fill('nova-senha-segura');
    await senha.locator('[name=password_confirmation]').fill('nova-senha-segura');
    await senha.locator('button[type=submit]').click();
    await expect(senha.locator('ul')).toBeVisible();
    await senha.locator('[name=current_password]').fill('senha-inicial-segura');
    await senha.locator('[name=password]').fill('nova-senha-segura');
    await senha.locator('[name=password_confirmation]').fill('nova-senha-segura');
    await senha.locator('button[type=submit]').click();
    await expect(senha.locator('p')).toContainText(/Salvo|Saved/);
    await page.goto('/');
    await page.locator('#formulario-sair button').click();
    await login(page, email, 'senha-inicial-segura');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('form ul')).toBeVisible();
    await login(page, email, 'nova-senha-segura');
    await expect(page).toHaveURL(/\/$/);
    await page.goto('/profile');
    await page.locator('button[x-on\\:click\\.prevent]').click();
    const exclusao = page.locator('form').filter({ has: page.locator('input[name=_method][value=delete]') });
    await expect(exclusao).toBeVisible();
    await exclusao.locator('[name=password]').fill('errada');
    await exclusao.locator('button[type=submit]').click();
    await expect(exclusao.locator('ul')).toBeVisible();
    await exclusao.locator('[name=password]').fill('nova-senha-segura');
    await exclusao.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page, email, 'nova-senha-segura');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('form ul')).toBeVisible();
});

test('recuperação usa link enviado e a senha antiga deixa de funcionar', async ({ page }) => {
    const email = `recuperacao-${Date.now()}@example.test`;
    await cadastrar(page, email);
    await page.locator('#formulario-sair button').click();
    await page.goto('/forgot-password');
    await page.locator('#email').fill(email);
    await page.locator('button[type=submit]').click();
    await expect(page.locator('.text-green-600')).toBeVisible();
    let link = '';
    await expect.poll(() => {
        const log = readFileSync('storage/logs/laravel.log', 'utf8');
        const links = log.match(/https?:\/\/[^\s<>]+\/reset-password\/[^\s<>]+/g) || [];
        link = links.findLast((url) => decodeURIComponent(url).includes(email)) || '';
        return link;
    }).not.toBe('');
    const destino = new URL(link);
    await page.goto(destino.pathname + destino.search);
    await page.locator('#password').fill('senha-recuperada-segura');
    await page.locator('#password_confirmation').fill('senha-recuperada-segura');
    await page.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page, email, 'senha-inicial-segura');
    await expect(page.locator('form ul')).toBeVisible();
    await login(page, email, 'senha-recuperada-segura');
    await expect(page).toHaveURL(/\/$/);
});
