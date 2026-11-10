<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

function escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectAdmin(): never
{
    header('Location: /admin.php', true, 303);
    exit;
}

function textField($value, int $max, string $label): string
{
    if (!is_string($value) || strlen($value) > $max * 4) {
        throw new DomainException("Некоректне поле «{$label}».");
    }
    $value = trim($value);
    if (preg_match('/\A.{1,' . $max . '}\z/us', $value) !== 1 || str_contains($value, "\0")) {
        throw new DomainException("Поле «{$label}»: від 1 до {$max} символів UTF-8.");
    }
    return $value;
}

function integerField($value, int $min, int $max): int
{
    if (!is_string($value) || preg_match('/\A[0-9]{1,10}\z/D', $value) !== 1) {
        throw new DomainException('Числові поля мають містити цілі невід’ємні числа.');
    }
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < $min || $number > $max) {
        throw new DomainException("Число має бути в межах {$min}–{$max}.");
    }
    return $number;
}

$enabled = false;
$authenticated = false;
$error = '';
$notice = '';
$csrf = '';
$biomes = $runs = $events = $stats = [];
$types = ['normal' => 'Звичайний', 'elite' => 'Елітний', 'boss' => 'Бос'];
$statuses = ['active' => 'Триває', 'victory' => 'Перемога', 'defeat' => 'Поразка'];
$field = static function (string $name, string $default = ''): string {
    $value = $_POST[$name] ?? $default;
    return is_string($value) ? $value : '';
};

try {
    $passwordHash = getenv('ADMIN_PASSWORD_HASH') ?: '';
    $enabled = (password_get_info($passwordHash)['algo'] ?? null) !== null
        && preg_match('~\A(?:\$2[aby]\$(?:0[4-9]|[12][0-9]|3[01])\$[./A-Za-z0-9]{53}'
            . '|\$argon2(?:i|id)\$v=19\$m=[1-9][0-9]*,t=[1-9][0-9]*,p=[1-9][0-9]*'
            . '\$[A-Za-z0-9+/]{8,}\$[A-Za-z0-9+/]{16,})\z~D', $passwordHash) === 1;
    if ($enabled) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '1800');
        session_name('geo_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/admin.php',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Session unavailable');
        }
        $fingerprint = hash('sha256', $passwordHash);
        $authenticated = isset($_SESSION['admin_hash'], $_SESSION['last_seen'])
            && is_string($_SESSION['admin_hash'])
            && hash_equals($fingerprint, $_SESSION['admin_hash'])
            && is_int($_SESSION['last_seen'])
            && $_SESSION['last_seen'] > time() - 1800;
        if (!$authenticated && isset($_SESSION['admin_hash'])) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        if ($authenticated) {
            $_SESSION['last_seen'] = time();
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $csrf = $_SESSION['csrf'];
        $notice = $_SESSION['notice'] ?? '';
        unset($_SESSION['notice']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
                http_response_code(403);
                throw new DomainException('Захисний код недійсний. Оновіть сторінку та повторіть дію.');
            }
            $action = $field('action');
            if ($action === 'login' && !$authenticated) {
                $password = $field('password');
                if ($password === '' || strlen($password) > 1024 || !password_verify($password, $passwordHash)) {
                    http_response_code(401);
                    throw new DomainException('Невірний пароль.');
                }
                $_SESSION = [
                    'admin_hash' => $fingerprint,
                    'last_seen' => time(),
                    'csrf' => bin2hex(random_bytes(32)),
                ];
                session_regenerate_id(true);
                redirectAdmin();
            }
            if (!$authenticated) {
                http_response_code(401);
                throw new DomainException('Спочатку увійдіть до панелі.');
            }
            if ($action === 'logout') {
                $_SESSION = [];
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 3600,
                    'path' => $cookie['path'],
                    'secure' => $cookie['secure'],
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
                session_destroy();
                redirectAdmin();
            }
            if ($action !== 'create') {
                throw new DomainException('Невідома дія.');
            }
        }

        if ($authenticated) {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_NAME')),
                getenv('DB_USER'),
                getenv('DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $query = static function (string $sql, array $params = []) use ($pdo): PDOStatement {
                $statement = $pdo->prepare($sql);
                $statement->execute($params);
                return $statement;
            };
            $biomes = $query('SELECT id, grade, title FROM biomes ORDER BY grade, id')->fetchAll();
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $field('action') === 'create') {
                try {
                    $biomeId = integerField($_POST['biome_id'] ?? null, 1, 2147483647);
                    if (!in_array($biomeId, array_map('intval', array_column($biomes, 'id')), true)) {
                        throw new DomainException('Оберіть наявний біом.');
                    }
                    $type = $field('node_type');
                    if (!isset($types[$type])) {
                        throw new DomainException('Оберіть тип вузла.');
                    }
                    $options = $_POST['options'] ?? null;
                    if (!is_array($options) || !array_is_list($options) || count($options) !== 3) {
                        throw new DomainException('Потрібно рівно три варіанти відповіді.');
                    }
                    $options = array_map(static fn($option) => textField($option, 500, 'Варіант відповіді'), $options);
                    $query(
                        'INSERT INTO events (biome_id, node_type, title, story_text, question_text, options,
                         correct_index, reward_xp, penalty_supplies, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$biomeId, $type, textField($_POST['title'] ?? null, 150, 'Назва'),
                            textField($_POST['story_text'] ?? null, 4000, 'Історія'),
                            textField($_POST['question_text'] ?? null, 2000, 'Питання'),
                            json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            integerField($_POST['correct_index'] ?? null, 0, 2),
                            integerField($_POST['reward_xp'] ?? null, 0, 1000),
                            integerField($_POST['penalty_supplies'] ?? null, 0, 100),
                            textField($_POST['explanation'] ?? null, 4000, 'Пояснення')]
                    );
                    $_SESSION['notice'] = 'Нове питання створено.';
                    redirectAdmin();
                } catch (DomainException $exception) {
                    http_response_code(400);
                    $error = $exception->getMessage();
                }
            }
            $stats = $query("SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'active'), 0) AS active,
                COALESCE(SUM(status = 'victory'), 0) AS victory,
                COALESCE(SUM(status = 'defeat'), 0) AS defeat,
                COALESCE(ROUND(AVG(score), 1), 0) AS average_score FROM runs")->fetch();
            // Run IDs are bearer credentials: never select or render them here.
            $runs = $query('SELECT r.student_name, r.grade, b.title AS biome_title, r.status, r.score,
                r.supplies, r.current_step, r.created_at FROM runs r
                JOIN biomes b ON b.id = r.biome_id ORDER BY r.created_at DESC LIMIT 50')->fetchAll();
            $events = $query('SELECT b.title AS biome_title, e.node_type, e.title, e.story_text,
                e.question_text, e.options, e.correct_index, e.reward_xp, e.penalty_supplies, e.explanation
                FROM events e JOIN biomes b ON b.id = e.biome_id ORDER BY e.id DESC LIMIT 50')->fetchAll();
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    $error = 'Панель тимчасово недоступна. Спробуйте пізніше.';
    $authenticated = false;
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Панель адміністратора — Хроніки Картографа</title>
    <link rel="stylesheet" href="/css/admin.css">
</head>
<body>
<main class="parchment">
    <header>
        <h1>Панель адміністратора</h1>
        <a href="/">← До експедиції</a>
        <?php if ($authenticated): ?>
        <form method="post" action="/admin.php">
            <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
            <button name="action" value="logout">Вийти</button>
        </form>
        <?php endif; ?>
    </header>
    <?php if ($error !== ''): ?><p class="message error" role="alert"><?= escape($error) ?></p><?php endif; ?>
    <?php if ($notice !== ''): ?><p class="message" role="status"><?= escape($notice) ?></p><?php endif; ?>
    <?php if (!$enabled): ?>
    <section>
        <h2>Панель вимкнена</h2>
        <p>Адміністратор має налаштувати коректний ADMIN_PASSWORD_HASH у .env.
            Інструкції наведено в .env.example. Типового пароля немає.</p>
        <p>Після налаштування виконайте <code>docker compose up -d --force-recreate php</code>.
            У production обов’язково використовуйте HTTPS.</p>
    </section>
    <?php elseif (!$authenticated): ?>
    <section class="login">
        <h2>Вхід</h2>
        <p>Сеанс завершується після 30 хвилин бездіяльності. Використовуйте HTTPS.</p>
        <form method="post" action="/admin.php">
            <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
            <label for="password">Пароль адміністратора</label>
            <input id="password" name="password" type="password" required maxlength="1024" autocomplete="current-password">
            <button name="action" value="login">Увійти</button>
        </form>
    </section>
    <?php elseif (!$stats): ?>
    <p><a href="/admin.php">Оновити панель</a></p>
    <?php else: ?>
    <section>
        <h2>Статистика експедицій</h2>
        <dl class="stats">
            <?php foreach (['total' => 'Усього', 'active' => 'Тривають', 'victory' => 'Перемоги',
                'defeat' => 'Поразки', 'average_score' => 'Середні очки'] as $key => $label): ?>
            <div><dt><?= escape($label) ?></dt><dd><?= escape($stats[$key]) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <h3>Останні 50 забігів</h3>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Останні забіги">
            <table>
                <thead><tr><th>Дослідник</th><th>Клас</th><th>Біом</th><th>Стан</th><th>Очки</th><th>Провізія</th><th>Крок</th><th>Дата</th></tr></thead>
                <tbody>
                <?php foreach ($runs as $run): ?>
                <tr>
                    <?php foreach (['student_name', 'grade', 'biome_title', 'status', 'score', 'supplies', 'current_step', 'created_at'] as $key): ?>
                    <td><?= escape($key === 'status' ? ($statuses[$run[$key]] ?? 'Невідомо') : $run[$key]) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (!$runs): ?><tr><td colspan="8">Забігів ще немає.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section>
        <h2>Додати питання</h2>
        <p>Доступне лише створення. Редагування й видалення вимкнені, щоб не порушити активні події.</p>
        <form method="post" action="/admin.php" class="question-form">
            <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
            <label for="biome">Біом</label>
            <select id="biome" name="biome_id" required>
                <?php foreach ($biomes as $biome): ?>
                <option value="<?= escape($biome['id']) ?>" <?= $field('biome_id') === (string)$biome['id'] ? 'selected' : '' ?>><?= escape($biome['grade'] . ' клас — ' . $biome['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="node-type">Тип вузла</label>
            <select id="node-type" name="node_type">
                <?php foreach ($types as $value => $label): ?>
                <option value="<?= escape($value) ?>" <?= $field('node_type') === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="title">Назва (до 150 символів)</label>
            <input id="title" name="title" maxlength="150" value="<?= escape($field('title')) ?>" required>
            <?php foreach (['story_text' => ['Історія', 4000], 'question_text' => ['Питання', 2000],
                'explanation' => ['Пояснення', 4000]] as $name => [$label, $max]): ?>
            <label for="<?= escape($name) ?>"><?= escape($label) ?> (до <?= escape($max) ?> символів)</label>
            <textarea id="<?= escape($name) ?>" name="<?= escape($name) ?>" maxlength="<?= escape($max) ?>" rows="3" required><?= escape($field($name)) ?></textarea>
            <?php endforeach; ?>
            <?php for ($index = 0; $index < 3; $index++):
                $option = $_POST['options'][$index] ?? '';
                if (!is_string($option)) $option = '';
            ?>
            <label for="option-<?= escape($index) ?>">Варіант <?= escape($index + 1) ?> (до 500 символів)</label>
            <input id="option-<?= escape($index) ?>" name="options[]" maxlength="500" value="<?= escape($option) ?>" required>
            <?php endfor; ?>
            <label for="correct">Правильний варіант</label>
            <select id="correct" name="correct_index">
                <?php for ($index = 0; $index < 3; $index++): ?>
                <option value="<?= escape($index) ?>" <?= $field('correct_index', '0') === (string)$index ? 'selected' : '' ?>><?= escape($index + 1) ?></option>
                <?php endfor; ?>
            </select>
            <label for="reward">Нагорода (0–1000 очок)</label>
            <input id="reward" type="number" name="reward_xp" min="0" max="1000" value="<?= escape($field('reward_xp', '25')) ?>" required>
            <label for="penalty">Штраф провізії (0–100)</label>
            <input id="penalty" type="number" name="penalty_supplies" min="0" max="100" value="<?= escape($field('penalty_supplies', '15')) ?>" required>
            <button name="action" value="create" <?= !$biomes ? 'disabled' : '' ?>>Створити питання</button>
        </form>
    </section>
    <section>
        <h2>Останні 50 питань</h2>
        <?php if (!$events): ?><p>Питань ще немає.</p><?php endif; ?>
        <?php foreach ($events as $event): ?>
        <details>
            <summary><?= escape($event['title']) ?> — <?= escape($event['biome_title']) ?> (<?= escape($types[$event['node_type']] ?? 'Невідомо') ?>)</summary>
            <p class="multiline"><?= escape($event['story_text']) ?></p>
            <p class="multiline"><strong><?= escape($event['question_text']) ?></strong></p>
            <ol>
                <?php foreach ((array)json_decode($event['options'], true) as $index => $option): ?>
                <li><?= escape(is_string($option) ? $option : '') ?><?= (int)$event['correct_index'] === $index ? ' ✓' : '' ?></li>
                <?php endforeach; ?>
            </ol>
            <p>Нагорода: <?= escape($event['reward_xp']) ?> · Штраф: <?= escape($event['penalty_supplies']) ?></p>
            <p class="multiline"><?= escape($event['explanation']) ?></p>
        </details>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
