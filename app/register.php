<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';

$next = authSafeNext((string)($_POST['next'] ?? $_GET['next'] ?? ''), '/decks.php');
if (preg_match('#^/(login|register|logout)\.php#', $next)) $next = '/decks.php';
if (authUser()) {
    header('Location: ' . $next, true, 303);
    exit;
}

$errors = [];
$data = ['full_name' => '', 'username' => '', 'email' => ''];
$generalError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        authVerifyCsrf();
        $result = authRegister($_POST);
        if ($result['ok']) {
            header('Location: ' . $next, true, 303);
            exit;
        }
        $errors = $result['errors'];
        $data = $result['data'];
    } catch (RuntimeException $e) {
        $generalError = $e->getMessage();
        $data = array_intersect_key(array_map('strval', $_POST), $data) + $data;
    }
}

$field = function (string $name, string $label, string $type, string $autocomplete, string $hint = '', array $extra = []) use ($errors, $data): void {
    $id = 'register-' . str_replace('_', '-', $name);
    $describedBy = trim(($hint !== '' ? $id . '-hint ' : '') . (isset($errors[$name]) ? $id . '-error' : ''));
    $attributes = '';
    foreach ($extra as $key => $value) $attributes .= ' ' . $key . '="' . h((string)$value) . '"';
    echo '<div class="auth-field' . (isset($errors[$name]) ? ' has-error' : '') . '"><label for="' . $id . '">' . h($label) . '</label>';
    $input = '<input id="' . $id . '" name="' . $name . '" type="' . $type . '" autocomplete="' . $autocomplete . '" required' . $attributes
        . ($type !== 'password' ? ' value="' . h($data[$name] ?? '') . '"' : '')
        . ($describedBy !== '' ? ' aria-describedby="' . $describedBy . '"' : '')
        . (isset($errors[$name]) ? ' aria-invalid="true"' : '') . '>';
    echo $type === 'password' ? '<span class="password-field">' . $input . '<button type="button" data-password-toggle aria-pressed="false">Mostrar</button></span>' : $input;
    if ($hint !== '') echo '<small id="' . $id . '-hint">' . h($hint) . '</small>';
    if (isset($errors[$name])) echo '<small class="field-error" id="' . $id . '-error">' . h($errors[$name]) . '</small>';
    echo '</div>';
};

pageHeader('Criar conta');
?>
<section class="auth-layout">
  <div class="auth-intro">
    <span class="auth-kicker">Novo por aqui</span>
    <h1>Seu espaço para montar decks.</h1>
    <p>Crie uma conta gratuita para guardar sua coleção, planejar decks de Commander e acompanhar cada troca.</p>
    <ul class="auth-benefits">
      <li><strong>Importe sua coleção</strong><span>CSV do ManaBox com impressões e foils.</span></li>
      <li><strong>Planeje com calma</strong><span>Candidatas → avaliação → deck de 100 cartas.</span></li>
      <li><strong>Privado por padrão</strong><span>Outras contas não veem seus decks nem sua coleção.</span></li>
    </ul>
  </div>
  <form class="auth-card" method="post" action="/register.php">
    <h2>Criar conta</h2>
    <?php if ($generalError): ?><p class="auth-message is-error" role="alert"><?= h($generalError) ?></p><?php elseif ($errors): ?><p class="auth-message is-error" role="alert">Revise os campos destacados.</p><?php endif; ?>
    <?= authCsrfField() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <?php $field('full_name', 'Nome completo', 'text', 'name', '', ['maxlength' => 120, 'autofocus' => 'autofocus']); ?>
    <?php $field('username', 'Nome de usuário', 'text', 'username', 'De 3 a 30 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.', ['maxlength' => 30, 'pattern' => '[A-Za-z0-9][A-Za-z0-9._\-]{1,28}[A-Za-z0-9]', 'autocapitalize' => 'none', 'spellcheck' => 'false']); ?>
    <?php $field('email', 'Email', 'email', 'email', '', ['maxlength' => 190]); ?>
    <?php $field('password', 'Senha', 'password', 'new-password', 'Pelo menos ' . AUTH_MIN_PASSWORD . ' caracteres. Uma frase longa é mais segura e fácil de lembrar.', ['minlength' => AUTH_MIN_PASSWORD, 'maxlength' => 72]); ?>
    <button class="primary-link auth-submit" type="submit">Criar conta</button>
    <p class="auth-switch">Já tem conta? <a href="/login.php<?= $next !== '/decks.php' ? '?next=' . h(rawurlencode($next)) : '' ?>">Entrar</a></p>
  </form>
</section>
<?php pageFooter(); ?>
