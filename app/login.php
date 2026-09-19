<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';

$next = authSafeNext((string)($_POST['next'] ?? $_GET['next'] ?? ''), '/');
if (preg_match('#^/(login|register|logout)\.php#', $next)) $next = '/';
if (authUser()) {
    header('Location: ' . $next, true, 303);
    exit;
}

$error = '';
$identifier = '';
$notice = (string)($_SESSION['auth_notice'] ?? '');
unset($_SESSION['auth_notice']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        authVerifyCsrf();
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $result = authAttemptLogin($identifier, (string)($_POST['password'] ?? ''), ($_POST['remember'] ?? '') === '1');
        if ($result['ok']) {
            header('Location: ' . $next, true, 303);
            exit;
        }
        $error = $result['message'];
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

pageHeader('Entrar');
?>
<section class="auth-layout">
  <div class="auth-intro">
    <span class="auth-kicker"><?= te('Sua mesa de montagem') ?></span>
    <h1><?= te('Bem-vindo de volta.') ?></h1>
    <p>Entre para continuar seus planejamentos, consultar sua coleção e registrar os próximos upgrades.</p>
    <ul class="auth-benefits">
      <li><strong><?= te('Coleção pessoal') ?></strong><span><?= te('Cópias, acabamentos e valores só seus.') ?></span></li>
      <li><strong><?= te('Decks em andamento') ?></strong><span><?= te('Candidatas e lista final preservadas.') ?></span></li>
    </ul>
  </div>
  <form class="auth-card" method="post" action="/login.php">
    <h2>Entrar</h2>
    <?php if ($notice): ?><p class="auth-message is-info" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="auth-message is-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?= authCsrfField() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label class="auth-field">Usuário ou email
      <input name="identifier" value="<?= h($identifier) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required <?= $identifier === '' ? 'autofocus' : '' ?>>
    </label>
    <label class="auth-field">Senha
      <span class="password-field"><input type="password" name="password" autocomplete="current-password" required <?= $identifier !== '' ? 'autofocus' : '' ?>><button type="button" data-password-toggle aria-pressed="false"><?= te('Mostrar') ?></button></span>
    </label>
    <label class="auth-check"><input type="checkbox" name="remember" value="1"> Manter conectado por 30 dias</label>
    <button class="primary-link auth-submit" type="submit">Entrar</button>
    <p class="auth-switch"><?= te('Ainda não tem conta? ') ?><a href="/register.php<?= $next !== '/' ? '?next=' . h(rawurlencode($next)) : '' ?>"><?= te('Criar conta') ?></a></p>
    <p class="auth-footnote"><?= te('Esqueceu a senha? Peça a um administrador do Deckarium para gerar uma senha temporária.') ?></p>
  </form>
</section>
<?php pageFooter(); ?>
