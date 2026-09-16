<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';

$admin = authRequireAdmin();
$adminId = (int)$admin['id'];
$message = (string)($_SESSION['users_message'] ?? '');
$temporaryPassword = $_SESSION['users_temporary_password'] ?? null;
unset($_SESSION['users_message'], $_SESSION['users_temporary_password']);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        authVerifyCsrf();
        $targetId = max(0, (int)($_POST['user'] ?? 0));
        $action = (string)($_POST['action'] ?? '');
        $stmt = db()->prepare('SELECT * FROM users WHERE id=?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) throw new RuntimeException('Usuário não encontrado.');
        if ($targetId === $adminId) throw new RuntimeException('Use “Minha conta” para alterar a sua própria conta.');

        if ($action === 'role') {
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
            if ($role === 'user' && $target['role'] === 'admin' && (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active")->fetchColumn() <= 1) {
                throw new RuntimeException('O Deckarium precisa manter pelo menos um administrador ativo.');
            }
            db()->prepare('UPDATE users SET role=?,updated_at=now() WHERE id=?')->execute([$role, $targetId]);
            $_SESSION['users_message'] = $role === 'admin' ? "@{$target['username']} agora é administrador." : "@{$target['username']} voltou a ser jogador.";
        } elseif ($action === 'active') {
            $active = ($_POST['active'] ?? '') === '1';
            db()->prepare('UPDATE users SET is_active=?,updated_at=now() WHERE id=?')->execute([$active ? 'true' : 'false', $targetId]);
            $_SESSION['users_message'] = $active ? "Conta de @{$target['username']} reativada." : "Conta de @{$target['username']} desativada. As sessões abertas foram encerradas.";
        } elseif ($action === 'reset_password') {
            $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $password = '';
            for ($i = 0; $i < 16; $i++) $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            authChangePassword($targetId, $password);
            $_SESSION['users_message'] = "Senha temporária gerada para @{$target['username']}. Ela aparece só desta vez.";
            $_SESSION['users_temporary_password'] = ['username' => $target['username'], 'password' => $password];
        } else {
            throw new RuntimeException('Ação inválida.');
        }
        header('Location: /users.php', true, 303);
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$q = substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$params = [];
$where = '';
if ($q !== '') {
    $where = 'WHERE u.full_name ILIKE ? OR u.username ILIKE ? OR u.email ILIKE ?';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $params = [$like, $like, $like];
}
$users = db()->prepare("SELECT u.id,u.full_name,u.username,u.email,u.role,u.is_active,u.created_at,u.last_login_at,
        (SELECT COUNT(*) FROM builder_decks d WHERE d.user_id=u.id) AS decks,
        (SELECT COALESCE(SUM(quantity),0) FROM builder_collection bc WHERE bc.user_id=u.id) AS cards
    FROM users u {$where} ORDER BY (u.role='admin') DESC, lower(u.full_name)");
$users->execute($params);
$users = $users->fetchAll();
$totals = db()->query("SELECT COUNT(*) total, COUNT(*) FILTER (WHERE role='admin') admins, COUNT(*) FILTER (WHERE NOT is_active) inactive, COUNT(*) FILTER (WHERE created_at > now() - interval '30 days') recent FROM users")->fetch();

$date = static fn(?string $value): string => $value ? date('d/m/Y H:i', strtotime($value)) : 'Nunca';

pageHeader('Usuários');
?>
<section class="hero"><div><h1>Usuários</h1><p>Contas cadastradas no Deckarium. Administradores acessam o Status do acervo e esta página.</p></div></section>
<?php if ($message): ?><p class="notice ok" role="status"><?= h($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
<?php if ($temporaryPassword): ?>
<div class="temporary-password" role="status">
  <div><strong>Senha temporária de @<?= h($temporaryPassword['username']) ?></strong><span>Envie por um canal seguro e peça para trocar em “Minha conta”.</span></div>
  <code><?= h($temporaryPassword['password']) ?></code>
</div>
<?php endif; ?>

<div class="account-summary">
  <div><span>Contas</span><strong><?= (int)$totals['total'] ?></strong><small><?= (int)$totals['recent'] ?> nos últimos 30 dias</small></div>
  <div><span>Administradores</span><strong><?= (int)$totals['admins'] ?></strong><small>Acesso ao Status</small></div>
  <div><span>Desativadas</span><strong><?= (int)$totals['inactive'] ?></strong><small>Sem acesso</small></div>
</div>

<section class="panel users-panel">
  <div class="section-heading users-heading">
    <h2>Todas as contas</h2>
    <form method="get" class="users-search"><label class="sr-only" for="users-q">Buscar usuário</label><input id="users-q" type="search" name="q" value="<?= h($q) ?>" placeholder="Nome, usuário ou email"><button class="secondary-link">Buscar</button><?php if ($q !== ''): ?><a href="/users.php">Limpar</a><?php endif; ?></form>
  </div>
  <div class="table-scroll">
    <table class="users-table">
      <thead><tr><th>Pessoa</th><th>Perfil</th><th>Decks</th><th>Cartas</th><th>Último acesso</th><th><span class="sr-only">Ações</span></th></tr></thead>
      <tbody>
      <?php foreach ($users as $row): $isSelf = (int)$row['id'] === $adminId; ?>
        <tr class="<?= $row['is_active'] ? '' : 'is-inactive' ?>">
          <td><strong><?= h($row['full_name']) ?></strong><small>@<?= h($row['username']) ?> · <?= h($row['email']) ?></small></td>
          <td><span class="role-pill <?= $row['role'] === 'admin' ? 'is-admin' : '' ?>"><?= $row['role'] === 'admin' ? 'Administrador' : 'Jogador' ?></span><?php if (!$row['is_active']): ?> <span class="role-pill is-inactive">Desativada</span><?php endif; ?></td>
          <td><?= number_format((int)$row['decks'], 0, ',', '.') ?></td>
          <td><?= number_format((int)$row['cards'], 0, ',', '.') ?></td>
          <td><?= h($date($row['last_login_at'])) ?><small>Criada em <?= h(date('d/m/Y', strtotime((string)$row['created_at']))) ?></small></td>
          <td class="users-actions">
            <?php if ($isSelf): ?><span class="muted">Você</span><?php else: ?>
            <details class="users-menu"><summary>Gerenciar</summary><div>
              <form method="post"><?= authCsrfField() ?><input type="hidden" name="user" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="role"><input type="hidden" name="role" value="<?= $row['role'] === 'admin' ? 'user' : 'admin' ?>"><button type="submit"><?= $row['role'] === 'admin' ? 'Remover administrador' : 'Tornar administrador' ?></button></form>
              <form method="post"><?= authCsrfField() ?><input type="hidden" name="user" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="reset_password"><button type="submit">Gerar senha temporária</button></form>
              <form method="post"><?= authCsrfField() ?><input type="hidden" name="user" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="active"><input type="hidden" name="active" value="<?= $row['is_active'] ? '0' : '1' ?>"><button type="submit" class="<?= $row['is_active'] ? 'is-danger' : '' ?>"><?= $row['is_active'] ? 'Desativar conta' : 'Reativar conta' ?></button></form>
            </div></details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="6">Nenhuma conta encontrada.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php pageFooter(); ?>
