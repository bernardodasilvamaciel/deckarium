<?php
declare(strict_types=1);

/**
 * Idiomas do Deckarium: português do Brasil (original) e inglês.
 *
 * A chave de tradução é o próprio texto em português, então em pt-BR nada é
 * procurado e o texto sai como está escrito no código. Em inglês, o texto é
 * buscado em lang/en.php; o que ainda não foi traduzido continua em português,
 * em vez de sumir da tela.
 */

const APP_LOCALES = ['pt-BR' => 'Português (BR)', 'en' => 'English'];
const APP_LOCALE_COOKIE = 'deckarium_lang';

/** Idioma da requisição: escolha na URL, sessão, conta, navegador e, por fim, português. */
function appLocale(): string
{
    static $locale = null;
    if ($locale !== null) return $locale;

    // "hl" e não "lang": o catálogo já usa lang= para o idioma impresso na carta.
    $requested = is_string($_GET['hl'] ?? null) ? appNormalizeLocale($_GET['hl']) : null;
    if ($requested) {
        $locale = $requested;
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['locale'] = $locale;
        setcookie(APP_LOCALE_COOKIE, $locale, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        appSaveUserLocale($locale);
        return $locale;
    }
    $user = function_exists('authUser') ? authUser() : null;
    return $locale = appNormalizeLocale((string)($_SESSION['locale'] ?? ''))
        ?? appNormalizeLocale((string)($user['locale'] ?? ''))
        ?? appNormalizeLocale((string)($_COOKIE[APP_LOCALE_COOKIE] ?? ''))
        ?? appBrowserLocale()
        ?? 'pt-BR';
}

function appNormalizeLocale(?string $value): ?string
{
    $value = strtolower(trim((string)$value));
    if ($value === '') return null;
    if (str_starts_with($value, 'en')) return 'en';
    if (str_starts_with($value, 'pt')) return 'pt-BR';
    return null;
}

/** Primeiro idioma aceito pelo navegador que o sistema conhece. */
function appBrowserLocale(): ?string
{
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $candidate = appNormalizeLocale(explode(';', $part)[0]);
        if ($candidate) return $candidate;
    }
    return null;
}

/** Guarda a escolha na conta, quando a coluna existe e há alguém conectado. */
function appSaveUserLocale(string $locale): void
{
    $userId = function_exists('authUserId') ? authUserId() : 0;
    if ($userId < 1) return;
    try {
        db()->prepare('UPDATE users SET locale=? WHERE id=?')->execute([$locale, $userId]);
        if (isset($GLOBALS['authUser']) && is_array($GLOBALS['authUser'])) $GLOBALS['authUser']['locale'] = $locale;
    } catch (Throwable) {
        // Instalação sem a coluna ainda: a escolha segue valendo pelo cookie.
    }
}

/**
 * Texto na língua da página.
 *
 * $vars troca marcações :nome pelo valor, já escapado quando o texto vai para HTML.
 */
function t(string $text, array $vars = []): string
{
    static $dictionary = null;
    if ($dictionary === null) {
        $locale = appLocale();
        $file = __DIR__ . '/lang/' . ($locale === 'en' ? 'en' : 'pt-BR') . '.php';
        $dictionary = is_file($file) ? (require $file) : [];
    }
    $translated = $dictionary[$text] ?? $text;
    if (!$vars) return $translated;
    // Do nome mais longo para o mais curto: senão :page trocaria dentro de :pages.
    uksort($vars, fn($a, $b) => strlen((string)$b) <=> strlen((string)$a));
    foreach ($vars as $key => $value) $translated = str_replace(':' . $key, (string)$value, $translated);
    return $translated;
}

/** Atalho para textos que vão direto no HTML. */
function te(string $text, array $vars = []): string
{
    return h(t($text, $vars));
}

/** Endereço da página atual trocando só o idioma, para o seletor do menu. */
function appLocaleUrl(string $locale): string
{
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
    $params = $_GET;
    $params['hl'] = $locale;
    return $path . '?' . http_build_query($params);
}
