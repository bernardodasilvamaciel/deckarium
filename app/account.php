<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';

$user = authRequireLogin();
$userId = (int)$user['id'];
$message = (string)($_SESSION['account_message'] ?? '');
$messageType = 'ok';
unset($_SESSION['account_message']);
$profileErrors = [];
$passwordErrors = [];
$profile = ['full_name' => $user['full_name'], 'username' => $user['username'], 'email' => $user['email']];

$currentPasswordMatches = static function (string $password) use ($userId): bool {
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=?');
    $stmt->execute([$userId]);
    return password_verify($password, (string)$stmt->fetchColumn());
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        authVerifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'profile') {
            [$profile, $profileErrors] = authValidateProfile($_POST, $userId);
            $sensitiveChange = $profile['username'] !== $user['username'] || $profile['email'] !== $user['email'];
            if (!$profileErrors && $sensitiveChange && !$currentPasswordMatches((string)($_POST['current_password'] ?? ''))) {
                $profileErrors['current_password'] = 'Confirme sua senha atual para alterar usuário ou email.';
            }
            if (!$profileErrors) {
                db()->prepare('UPDATE users SET full_name=?,username=?,email=?,updated_at=now() WHERE id=?')
                    ->execute([$profile['full_name'], $profile['username'], $profile['email'], $userId]);
                $_SESSION['account_message'] = 'Dados da conta atualizados.';
                header('Location: /account.php', true, 303);
                exit;
            }
        } elseif ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            if (authTooManyAttempts('password', (string)$userId, 5, 15)) {
                $passwordErrors['current_password'] = 'Muitas tentativas. Aguarde 15 minutos.';
            } elseif (!$currentPasswordMatches($current)) {
                authRecordAttempt('password', (string)$userId, false);
                $passwordErrors['current_password'] = 'A senha atual não confere.';
            } elseif ($error = authValidatePassword($new, [$user['username'], strstr($user['email'], '@', true), ...explode(' ', $user['full_name'])])) {
                $passwordErrors['new_password'] = $error;
            } elseif (hash_equals($current, $new)) {
                $passwordErrors['new_password'] = 'Escolha uma senha diferente da atual.';
            }
            if (!$passwordErrors) {
                authChangePassword($userId, $new);
                $_SESSION['account_message'] = 'Senha alterada. As outras sessões abertas desta conta foram encerradas.';
                header('Location: /account.php', true, 303);
                exit;
            }
        } else {
            throw new RuntimeException('Ação inválida.');
        }
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$stats = db()->prepare("SELECT
    (SELECT COUNT(*) FROM builder_decks WHERE user_id=?) AS decks,
    (SELECT COUNT(*) FROM builder_decks WHERE user_id=? AND status='ready') AS ready_decks,
    (SELECT COALESCE(SUM(quantity),0) FROM builder_collection WHERE user_id=?) AS cards,
    (SELECT COUNT(*) FROM builder_collection WHERE user_id=?) AS printings");
$stats->execute([$userId, $userId, $userId, $userId]);
$stats = $stats->fetch();

$error = static function (array $errors, string $name): string {
    return isset($errors[$name]) ? '<small class="field-error">' . h($errors[$name]) . '</small>' : '';
};

pageHeader('Minha conta');
?>
<section class="hero"><div><h1>Minha conta</h1><p>Seus dados de acesso e um resumo do que está guardado no seu nome.</p></div></section>
<?php if ($message): ?><p class="notice <?= $messageType ?>" role="status"><?= h($message) ?></p><?php endif; ?>

<div class="account-summary">
  <div><span>Perfil</span><strong><?= $user['role'] === 'admin' ? 'Administrador' : 'Jogador' ?></strong><small>Desde <?= h(date('d/m/Y', strtotime((string)$user['created_at']))) ?></small></div>
  <div><span>Decks</span><strong><?= number_format((int)$stats['decks'], 0, ',', '.') ?></strong><small><?= (int)$stats['ready_decks'] ?> finalizado(s)</small></div>
  <div><span>Coleção</span><strong><?= number_format((int)$stats['cards'], 0, ',', '.') ?></strong><small><?= number_format((int)$stats['printings'], 0, ',', '.') ?> versões</small></div>
  <div><span>Último acesso</span><strong><?= $user['last_login_at'] ? h(date('d/m', strtotime((string)$user['last_login_at']))) : '—' ?></strong><small><?= $user['last_login_at'] ? h(date('H:i', strtotime((string)$user['last_login_at']))) : 'Primeiro acesso' ?></small></div>
</div>

<div class="account-grid">
  <form class="panel builder-form account-form" method="post">
    <?= authCsrfField() ?><input type="hidden" name="action" value="profile">
    <h2>Dados pessoais</h2>
    <label class="<?= isset($profileErrors['full_name']) ? 'has-error' : '' ?>">Nome completo<input name="full_name" value="<?= h($profile['full_name']) ?>" autocomplete="name" maxlength="120" required><?= $error($profileErrors, 'full_name') ?></label>
    <label class="<?= isset($profileErrors['username']) ? 'has-error' : '' ?>">Nome de usuário<input name="username" value="<?= h($profile['username']) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="30" required><?= $error($profileErrors, 'username') ?></label>
    <label class="<?= isset($profileErrors['email']) ? 'has-error' : '' ?>">Email<input type="email" name="email" value="<?= h($profile['email']) ?>" autocomplete="email" maxlength="190" required><?= $error($profileErrors, 'email') ?></label>
    <label class="<?= isset($profileErrors['current_password']) ? 'has-error' : '' ?>">Senha atual <span class="label-note">só para mudar usuário ou email</span><span class="password-field"><input type="password" name="current_password" autocomplete="current-password"><button type="button" data-password-toggle aria-pressed="false">Mostrar</button></span><?= $error($profileErrors, 'current_password') ?></label>
    <button class="primary-link">Salvar dados</button>
  </form>

  <form class="panel builder-form account-form" method="post">
    <?= authCsrfField() ?><input type="hidden" name="action" value="password">
    <h2>Alterar senha</h2>
    <p class="muted">Ao trocar a senha, outros navegadores conectados a esta conta precisarão entrar de novo.</p>
    <input type="text" name="username" value="<?= h($user['username']) ?>" autocomplete="username" hidden>
    <label class="<?= isset($passwordErrors['current_password']) ? 'has-error' : '' ?>">Senha atual<span class="password-field"><input type="password" name="current_password" autocomplete="current-password" required><button type="button" data-password-toggle aria-pressed="false">Mostrar</button></span><?= $error($passwordErrors, 'current_password') ?></label>
    <label class="<?= isset($passwordErrors['new_password']) ? 'has-error' : '' ?>">Nova senha<span class="password-field"><input type="password" name="new_password" autocomplete="new-password" minlength="<?= AUTH_MIN_PASSWORD ?>" maxlength="72" required><button type="button" data-password-toggle aria-pressed="false">Mostrar</button></span><small>Pelo menos <?= AUTH_MIN_PASSWORD ?> caracteres.</small><?= $error($passwordErrors, 'new_password') ?></label>
    <button class="primary-link">Alterar senha</button>
  </form>
</div>
<?php pageFooter(); ?>
