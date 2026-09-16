<?php
declare(strict_types=1);

/**
 * Autenticação do Deckarium: sessões, contas, papéis e posse dos dados.
 *
 * Carregado por partials.php (páginas) e pelos endpoints JSON. Na primeira
 * requisição após a atualização, cria as tabelas de usuários, o administrador
 * inicial (app/auth_bootstrap.php) e transfere a coleção e os decks existentes
 * para ele.
 */

require_once __DIR__ . '/db.php';

const AUTH_SESSION_NAME = 'deckarium_session';
const AUTH_REMEMBER_SECONDS = 2592000; // 30 dias
const AUTH_IDLE_SECONDS = 43200;       // 12 horas sem "manter conectado"
const AUTH_MAX_FAILURES = 8;           // por usuário/IP em 15 minutos
const AUTH_MIN_PASSWORD = 10;

function authStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)AUTH_REMEMBER_SECONDS);
    session_name(AUTH_SESSION_NAME);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function authMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    try {
        $applied = $pdo->query("SELECT name FROM app_migrations WHERE name IN ('auth_v1','ownership_v1')")->fetchAll(PDO::FETCH_COLUMN);
        if (count($applied) === 2) return;
    } catch (PDOException) {
        // Tabela de migrações ainda não existe.
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_migrations (name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())");
    $pdo->beginTransaction();
    try {
        $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('deckarium-auth-migration'))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id bigserial PRIMARY KEY,
                full_name text NOT NULL,
                username text NOT NULL,
                email text NOT NULL,
                password_hash text NOT NULL,
                role text NOT NULL DEFAULT 'user' CHECK (role IN ('user','admin')),
                is_active boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                password_changed_at timestamptz NOT NULL DEFAULT now(),
                last_login_at timestamptz NULL);
            CREATE UNIQUE INDEX IF NOT EXISTS users_username_unique ON users (lower(username));
            CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (lower(email));
            CREATE TABLE IF NOT EXISTS auth_attempts (
                id bigserial PRIMARY KEY,
                kind text NOT NULL,
                identifier text NOT NULL,
                ip text NOT NULL,
                succeeded boolean NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now());
            CREATE INDEX IF NOT EXISTS auth_attempts_lookup_idx ON auth_attempts (kind, created_at DESC);
            INSERT INTO app_migrations(name) VALUES ('auth_v1') ON CONFLICT DO NOTHING;");

        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $bootstrapFile = __DIR__ . '/auth_bootstrap.php';
        if ($userCount === 0 && is_file($bootstrapFile)) {
            $admin = require $bootstrapFile;
            $stmt = $pdo->prepare("INSERT INTO users(full_name,username,email,password_hash,role) VALUES (?,?,?,?,'admin')");
            $stmt->execute([$admin['full_name'], strtolower($admin['username']), strtolower($admin['email']), $admin['password_hash']]);
        }

        $owner = $pdo->query("SELECT id FROM users ORDER BY (role='admin') DESC, id LIMIT 1")->fetchColumn();
        $ownershipDone = (bool)$pdo->query("SELECT 1 FROM app_migrations WHERE name='ownership_v1'")->fetchColumn();
        if ($owner && !$ownershipDone) {
            authMigrateOwnership($pdo, (int)$owner);
            $pdo->exec("INSERT INTO app_migrations(name) VALUES ('ownership_v1') ON CONFLICT DO NOTHING");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Coloca user_id nas tabelas pessoais e entrega os dados existentes ao primeiro administrador. */
function authMigrateOwnership(PDO $pdo, int $ownerId): void
{
    // Garante que as tabelas do construtor existam mesmo em instalações novas.
    $pdo->exec("CREATE TABLE IF NOT EXISTS builder_decks (
            id bigserial PRIMARY KEY, name text NOT NULL, commander_id uuid REFERENCES cards(id),
            strategy text NOT NULL DEFAULT '', terms text NOT NULL DEFAULT '', status text NOT NULL DEFAULT 'planning', created_at timestamptz DEFAULT now());
        CREATE TABLE IF NOT EXISTS builder_collection (
            scryfall_id uuid NOT NULL, name text NOT NULL, quantity int NOT NULL CHECK(quantity > 0),
            foil boolean NOT NULL DEFAULT false);
        ALTER TABLE builder_collection ADD COLUMN IF NOT EXISTS foil boolean NOT NULL DEFAULT false;");
    foreach (['builder_decks', 'builder_collection'] as $table) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS user_id bigint REFERENCES users(id) ON DELETE CASCADE");
        $pdo->prepare("UPDATE {$table} SET user_id=? WHERE user_id IS NULL")->execute([$ownerId]);
        $pdo->exec("ALTER TABLE {$table} ALTER COLUMN user_id SET NOT NULL");
    }
    $pdo->exec("ALTER TABLE builder_collection DROP CONSTRAINT IF EXISTS builder_collection_pkey;
        ALTER TABLE builder_collection ADD CONSTRAINT builder_collection_pkey PRIMARY KEY (user_id, scryfall_id, foil);
        CREATE INDEX IF NOT EXISTS builder_decks_user_idx ON builder_decks (user_id, id DESC);
        CREATE INDEX IF NOT EXISTS builder_collection_scryfall_idx ON builder_collection (scryfall_id);");
}

function authBoot(): void
{
    static $booted = false;
    if ($booted) return;
    $booted = true;
    authStartSession();
    authMigrate();
    authLoadUser();
    $_SESSION['auth_csrf'] ??= bin2hex(random_bytes(24));
}

function authLoadUser(): ?array
{
    static $loaded = false;
    if ($loaded) return $GLOBALS['authUser'] ?? null;
    $loaded = true;
    $GLOBALS['authUser'] = null;
    $userId = (int)($_SESSION['auth_user_id'] ?? 0);
    if ($userId < 1) return null;

    $idleLimit = !empty($_SESSION['auth_remember']) ? AUTH_REMEMBER_SECONDS : AUTH_IDLE_SECONDS;
    if (time() - (int)($_SESSION['auth_seen_at'] ?? 0) > $idleLimit) {
        authClearSession();
        $_SESSION['auth_notice'] = 'Sua sessão expirou. Entre novamente.';
        return null;
    }
    $stmt = db()->prepare('SELECT id,full_name,username,email,role,is_active,password_hash,created_at,last_login_at FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    // Trocar a senha ou desativar a conta encerra as outras sessões.
    if (!$user || !$user['is_active'] || !hash_equals((string)($_SESSION['auth_fingerprint'] ?? ''), authFingerprint($user))) {
        authClearSession();
        return null;
    }
    $_SESSION['auth_seen_at'] = time();
    unset($user['password_hash']);
    return $GLOBALS['authUser'] = $user;
}

function authFingerprint(array $user): string
{
    return hash_hmac('sha256', (string)$user['id'] . '|' . (string)$user['password_hash'] . '|' . ($user['is_active'] ? '1' : '0'), 'deckarium-session');
}

function authUser(): ?array
{
    authBoot();
    return $GLOBALS['authUser'] ?? null;
}

function authUserId(): int
{
    return (int)(authUser()['id'] ?? 0);
}

function authIsAdmin(): bool
{
    return (authUser()['role'] ?? '') === 'admin';
}

function authWantsJson(): bool
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json') || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function authSafeNext(?string $next, string $fallback = '/'): string
{
    $next = (string)$next;
    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\') || preg_match('/[\r\n]/', $next)) return $fallback;
    return $next;
}

function authRequireLogin(): array
{
    $user = authUser();
    if ($user) return $user;
    if (authWantsJson() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Entre na sua conta para continuar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: /login.php?next=' . rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/')), true, 303);
    exit;
}

function authRequireAdmin(): array
{
    $user = authRequireLogin();
    if ($user['role'] === 'admin') return $user;
    http_response_code(403);
    if (authWantsJson() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !function_exists('pageHeader')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Apenas administradores podem acessar esta área.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    pageHeader('Acesso restrito');
    echo '<section class="empty-state"><h1>Acesso restrito</h1><p>Esta área é exclusiva para administradores do Deckarium.</p><a class="primary-link" href="/">Voltar ao início</a></section>';
    pageFooter();
    exit;
}

function authCsrfToken(): string
{
    authBoot();
    return (string)$_SESSION['auth_csrf'];
}

function authCsrfField(): string
{
    return '<input type="hidden" name="auth_csrf" value="' . htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function authVerifyCsrf(): void
{
    if (!hash_equals(authCsrfToken(), (string)($_POST['auth_csrf'] ?? ''))) {
        throw new RuntimeException('Sessão expirada. Recarregue a página e tente novamente.');
    }
}

function authClientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function authTooManyAttempts(string $kind, string $identifier, int $limit, int $minutes): bool
{
    $stmt = db()->prepare("SELECT
            COUNT(*) FILTER (WHERE identifier = ?) AS by_identifier,
            COUNT(*) AS by_ip
        FROM auth_attempts
        WHERE kind = ? AND NOT succeeded AND created_at > now() - make_interval(mins => ?) AND (identifier = ? OR ip = ?)");
    $stmt->execute([$identifier, $kind, $minutes, $identifier, authClientIp()]);
    $row = $stmt->fetch();
    return (int)$row['by_identifier'] >= $limit || (int)$row['by_ip'] >= $limit * 3;
}

function authRecordAttempt(string $kind, string $identifier, bool $succeeded): void
{
    db()->prepare('INSERT INTO auth_attempts(kind,identifier,ip,succeeded) VALUES (?,?,?,?)')
        ->execute([$kind, substr($identifier, 0, 200), authClientIp(), $succeeded ? 'true' : 'false']);
    if (random_int(1, 50) === 1) db()->exec("DELETE FROM auth_attempts WHERE created_at < now() - interval '30 days'");
}

function authHashPassword(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function authValidatePassword(string $password, array $context = []): ?string
{
    if (mb_strlen($password) < AUTH_MIN_PASSWORD) return 'A senha precisa ter pelo menos ' . AUTH_MIN_PASSWORD . ' caracteres.';
    if (strlen($password) > 72) return 'A senha pode ter no máximo 72 caracteres.';
    $lower = mb_strtolower($password);
    foreach ($context as $value) {
        $value = mb_strtolower(trim((string)$value));
        if ($value !== '' && mb_strlen($value) >= 4 && str_contains($lower, $value)) return 'Evite usar seu nome, usuário ou email na senha.';
    }
    if (in_array($lower, ['1234567890', 'senha12345', 'password123', 'qwertyuiop', 'deckarium123'], true)) return 'Escolha uma senha menos previsível.';
    return null;
}

/** @return array{0: array<string,string>, 1: array<string,string>} [dados normalizados, erros por campo] */
function authValidateProfile(array $input, ?int $ignoreUserId = null): array
{
    $data = [
        'full_name' => trim(preg_replace('/\s+/u', ' ', (string)($input['full_name'] ?? '')) ?? ''),
        'username' => strtolower(trim((string)($input['username'] ?? ''))),
        'email' => strtolower(trim((string)($input['email'] ?? ''))),
    ];
    $errors = [];
    if (mb_strlen($data['full_name']) < 3 || mb_strlen($data['full_name']) > 120 || !preg_match('/\p{L}/u', $data['full_name'])) $errors['full_name'] = 'Informe seu nome completo.';
    if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]{1,28})[a-z0-9]$/', $data['username'])) $errors['username'] = 'Use de 3 a 30 caracteres: letras, números, ponto, hífen ou sublinhado.';
    if (strlen($data['email']) > 190 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Informe um email válido.';
    if (!isset($errors['username'])) {
        $stmt = db()->prepare('SELECT 1 FROM users WHERE lower(username)=? AND id<>?');
        $stmt->execute([$data['username'], $ignoreUserId ?? 0]);
        if ($stmt->fetchColumn()) $errors['username'] = 'Este nome de usuário já está em uso.';
    }
    if (!isset($errors['email'])) {
        $stmt = db()->prepare('SELECT 1 FROM users WHERE lower(email)=? AND id<>?');
        $stmt->execute([$data['email'], $ignoreUserId ?? 0]);
        if ($stmt->fetchColumn()) $errors['email'] = 'Já existe uma conta com este email.';
    }
    return [$data, $errors];
}

function authRegister(array $input): array
{
    authBoot();
    if (authTooManyAttempts('register', authClientIp(), 5, 60)) throw new RuntimeException('Muitas contas criadas a partir desta conexão. Tente novamente mais tarde.');
    [$data, $errors] = authValidateProfile($input);
    $password = (string)($input['password'] ?? '');
    if ($passwordError = authValidatePassword($password, [$data['username'], $data['email'] !== '' ? strstr($data['email'], '@', true) : '', ...explode(' ', $data['full_name'])])) $errors['password'] = $passwordError;
    if ($errors) return ['ok' => false, 'errors' => $errors, 'data' => $data];

    try {
        $stmt = db()->prepare("INSERT INTO users(full_name,username,email,password_hash,role) VALUES (?,?,?,?,'user') RETURNING *");
        $stmt->execute([$data['full_name'], $data['username'], $data['email'], authHashPassword($password)]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') return ['ok' => false, 'errors' => ['username' => 'Nome de usuário ou email já cadastrado.'], 'data' => $data];
        throw $e;
    }
    // Registrado como "não concluído" para entrar no limite de cadastros por IP.
    authRecordAttempt('register', authClientIp(), false);
    authSignIn($user, false);
    return ['ok' => true, 'user' => $user];
}

function authAttemptLogin(string $identifier, string $password, bool $remember): array
{
    authBoot();
    $identifier = strtolower(trim($identifier));
    if ($identifier === '' || $password === '') return ['ok' => false, 'message' => 'Informe usuário ou email e senha.'];
    if (authTooManyAttempts('login', $identifier, AUTH_MAX_FAILURES, 15)) {
        return ['ok' => false, 'message' => 'Muitas tentativas sem sucesso. Aguarde 15 minutos e tente novamente.'];
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE lower(username)=? OR lower(email)=? LIMIT 1');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();
    // Mesmo custo de verificação quando o usuário não existe.
    $valid = password_verify($password, $user['password_hash'] ?? '$2y$12$waXm.QYQquWq2aJ0Ks/FgeV83y0mtBxL/X0BVoOp1Clksd33Sf5.y');
    if (!$user || !$valid) {
        authRecordAttempt('login', $identifier, false);
        return ['ok' => false, 'message' => 'Usuário, email ou senha incorretos.'];
    }
    if (!$user['is_active']) {
        authRecordAttempt('login', $identifier, false);
        return ['ok' => false, 'message' => 'Esta conta está desativada. Fale com um administrador.'];
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $user['password_hash'] = authHashPassword($password);
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$user['password_hash'], $user['id']]);
    }
    authRecordAttempt('login', $identifier, true);
    authSignIn($user, $remember);
    return ['ok' => true, 'user' => $user];
}

function authSignIn(array $user, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['auth_user_id'] = (int)$user['id'];
    $_SESSION['auth_fingerprint'] = authFingerprint($user);
    $_SESSION['auth_remember'] = $remember;
    $_SESSION['auth_seen_at'] = time();
    $_SESSION['auth_csrf'] = bin2hex(random_bytes(24));
    if ($remember) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), ['expires' => time() + AUTH_REMEMBER_SECONDS, 'path' => '/', 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    db()->prepare('UPDATE users SET last_login_at=now() WHERE id=?')->execute([$user['id']]);
    unset($user['password_hash']);
    $GLOBALS['authUser'] = $user;
}

function authClearSession(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $GLOBALS['authUser'] = null;
}

function authSignOut(): void
{
    authClearSession();
    $_SESSION['auth_csrf'] = bin2hex(random_bytes(24));
}

/** Atualiza a senha e mantém somente a sessão atual conectada. */
function authChangePassword(int $userId, string $newPassword): void
{
    $hash = authHashPassword($newPassword);
    db()->prepare('UPDATE users SET password_hash=?,password_changed_at=now(),updated_at=now() WHERE id=?')->execute([$hash, $userId]);
    $stmt = db()->prepare('SELECT * FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user && authUserId() === $userId) {
        session_regenerate_id(true);
        $_SESSION['auth_fingerprint'] = authFingerprint($user);
    }
}

authBoot();
