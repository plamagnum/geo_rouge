<?php
declare(strict_types=1);

final class StudentAuth
{
    private PDO $db;
    private const DUMMY_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(PDO $db)
    {
        $this->db = $db;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '1800');
        session_name('geo_student');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/api.php',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true, 'samesite' => 'Strict',
        ]);
        if (!session_start()) throw new RuntimeException('Session unavailable');
        if (isset($_SESSION['user_id']) && (!is_int($_SESSION['last_seen'] ?? null)
            || $_SESSION['last_seen'] <= time() - 1800)) {
            $this->reset();
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function normalizeLogin($value): string
    {
        if (!is_string($value) || strlen($value) > 64) {
            throw new DomainException('Логін: 3–64 символи a–z, 0–9, крапка, дефіс або підкреслення.');
        }
        $value = strtolower(trim($value));
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,63}\z/D', $value) !== 1) {
            throw new DomainException('Логін: 3–64 символи a–z, 0–9, крапка, дефіс або підкреслення; початок — літера чи цифра.');
        }
        return $value;
    }

    public static function hashPassword($value): string
    {
        if (!is_string($value) || strlen($value) < 12 || strlen($value) > 72 || str_contains($value, "\0")) {
            throw new DomainException('Пароль має містити 12–72 байти без нульового символу (українські літери займають кілька байтів).');
        }
        return password_hash($value, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public function csrf(): string
    {
        return $_SESSION['csrf'];
    }

    public function validCsrf($token): bool
    {
        return is_string($token) && hash_equals($this->csrf(), $token);
    }

    public function currentUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) return null;
        $query = $this->db->prepare('SELECT id, login, display_name, grade, is_blocked, auth_version FROM users WHERE id = ?');
        $query->execute([$_SESSION['user_id']]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['is_blocked'] !== 0
            || (int)$user['auth_version'] !== ($_SESSION['auth_version'] ?? null)) {
            $this->reset();
            return null;
        }
        $_SESSION['last_seen'] = time();
        return $this->profile($user);
    }

    public function login($login, $password): ?array
    {
        try {
            $login = self::normalizeLogin($login);
        } catch (DomainException $exception) {
            $login = '';
        }
        $query = $this->db->prepare('SELECT id, login, display_name, grade, password_hash, is_blocked, auth_version FROM users WHERE login = ?');
        $query->execute([$login]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        $validPassword = is_string($password) && strlen($password) >= 12
            && strlen($password) <= 72 && !str_contains($password, "\0");
        $verified = password_verify($validPassword ? $password : '', $user['password_hash'] ?? self::DUMMY_HASH);
        if (!$validPassword || !$verified || !$user || (int)$user['is_blocked'] !== 0) return null;
        $this->reset();
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['auth_version'] = (int)$user['auth_version'];
        $_SESSION['last_seen'] = time();
        return $this->profile($user);
    }

    public function reset(): void
    {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        if (!session_regenerate_id(true)) throw new RuntimeException('Session unavailable');
    }

    private function profile(array $user): array
    {
        return ['id' => (int)$user['id'], 'login' => $user['login'],
            'display_name' => $user['display_name'], 'grade' => (int)$user['grade']];
    }
}
