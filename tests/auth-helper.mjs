// Entra no Deckarium antes dos testes de fluxo. Defina DECKARIUM_USER e DECKARIUM_PASSWORD.
import assert from 'node:assert/strict';

export async function login(base) {
  const user = process.env.DECKARIUM_USER;
  const password = process.env.DECKARIUM_PASSWORD;
  assert(user && password, 'Defina DECKARIUM_USER e DECKARIUM_PASSWORD para rodar os testes autenticados.');
  let cookie = '';
  const keep = response => { const set = response.headers.get('set-cookie'); if (set) cookie = set.split(';')[0]; return response; };
  const page = keep(await fetch(base + '/login.php', { redirect: 'manual' }));
  const html = await page.text();
  const token = html.match(/name="auth_csrf" value="([^"]+)"/)?.[1];
  assert(token, 'Formulário de login sem token CSRF.');
  const response = keep(await fetch(base + '/login.php', {
    method: 'POST', redirect: 'manual', headers: { cookie },
    body: new URLSearchParams({ auth_csrf: token, identifier: user, password }),
  }));
  assert.equal(response.status, 303, 'Login recusado para os testes.');
  return cookie;
}
