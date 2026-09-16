<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . (authUser() ? '/account.php' : '/'), true, 303);
    exit;
}
try {
    authVerifyCsrf();
    authSignOut();
    $_SESSION['auth_notice'] = 'Você saiu da sua conta.';
    header('Location: /login.php', true, 303);
} catch (RuntimeException) {
    header('Location: /account.php', true, 303);
}
exit;
