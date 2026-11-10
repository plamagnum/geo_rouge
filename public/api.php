<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    $action = $_GET['action'] ?? '';
    $readActions = ['catalog', 'session', 'state'];
    $writeActions = ['login', 'logout', 'start', 'encounter', 'answer'];
    if (!is_string($action) || !in_array($action, array_merge($readActions, $writeActions), true)) {
        throw new DomainException('Невідома дія.');
    }
    // Keep PHP's action identical to nginx's raw $arg_action (no duplicate/encoded login bypass).
    if (preg_match('/\Aaction=' . preg_quote($action, '/') . '(?:&run_id=[a-f0-9]{32})?\z/D',
        $_SERVER['QUERY_STRING'] ?? '') !== 1) {
        throw new DomainException('Некоректні параметри адреси.');
    }
    $expectedMethod = in_array($action, $readActions, true) ? 'GET' : 'POST';
    if ($_SERVER['REQUEST_METHOD'] !== $expectedMethod) {
        header('Allow: ' . $expectedMethod);
        http_response_code(405);
        throw new DomainException('Метод запиту не підтримується.');
    }
    $input = [];
    if ($expectedMethod === 'POST') {
        $body = file_get_contents('php://input', false, null, 0, 16385);
        if ($body === false || strlen($body) > 16384) throw new DomainException('Запит завеликий.');
        $input = $body === '' ? [] : json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new DomainException('Некоректні дані запиту.');
    }
    $integer = static function ($value): int {
        if (!is_int($value)) throw new DomainException('Очікується ціле число.');
        return $value;
    };
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_NAME')),
        getenv('DB_USER'), getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    require_once __DIR__ . '/../src/StudentAuth.php';
    require_once __DIR__ . '/../src/GameEngine.php';
    $auth = new StudentAuth($pdo);
    $user = $auth->currentUser();
    if ($expectedMethod === 'POST' && !$auth->validCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        http_response_code(403);
        throw new DomainException('Захисний код недійсний. Оновіть сторінку.');
    }
    if ($action === 'session') {
        $result = ['user' => $user, 'csrf' => $auth->csrf()];
    } elseif ($action === 'login') {
        $user = $auth->login($input['login'] ?? null, $input['password'] ?? null);
        if (!$user) {
            http_response_code(401);
            throw new DomainException('Невірний логін або пароль чи обліковий запис недоступний.');
        }
        $result = ['user' => $user, 'csrf' => $auth->csrf()];
    } elseif ($action === 'logout') {
        $auth->reset();
        $result = ['user' => null, 'csrf' => $auth->csrf()];
    } else {
        if (!$user) {
            http_response_code(401);
            throw new DomainException('Увійдіть до облікового запису учня.');
        }
        session_write_close();
        $engine = new GameEngine($pdo);
        if (in_array($action, ['encounter', 'answer', 'state'], true)) {
            $runId = $action === 'state' ? ($_GET['run_id'] ?? null) : ($input['run_id'] ?? null);
            if (!is_string($runId) || preg_match('/\A[a-f0-9]{32}\z/D', $runId) !== 1) {
                throw new DomainException('Некоректний ідентифікатор забігу.');
            }
            $query = $pdo->prepare('SELECT id FROM runs WHERE id = ? AND user_id = ?');
            $query->execute([$runId, $user['id']]);
            if (!$query->fetchColumn()) {
                http_response_code(403);
                throw new DomainException('Забіг недоступний.');
            }
        }
        switch ($action) {
            case 'catalog':
                $result = $pdo->query('SELECT b.id, b.grade, b.title, b.description,
                    (SELECT COUNT(*) FROM events e WHERE e.biome_id = b.id) AS event_count
                    FROM biomes b ORDER BY b.grade, b.id')->fetchAll();
                break;
            case 'start':
                $result = $engine->startRun($user['display_name'], $user['grade'],
                    $integer($input['biome_id'] ?? null),
                    isset($input['seed']) ? $integer($input['seed']) : null, $user['id']);
                break;
            case 'encounter':
                $nodeId = $input['node_id'] ?? null;
                if (!is_string($nodeId) || strlen($nodeId) > 32) throw new DomainException('Некоректний вузол.');
                $result = $engine->getEncounter($runId, $nodeId);
                break;
            case 'answer':
                $result = $engine->submitAnswer($runId, $integer($input['event_id'] ?? null),
                    $integer($input['chosen_index'] ?? null));
                break;
            case 'state':
                $result = $engine->getRunState($runId);
                break;
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $domainError = $exception instanceof DomainException || $exception instanceof JsonException;
    if (http_response_code() < 400) http_response_code($domainError ? 400 : 500);
    echo json_encode(['error' => $domainError ? $exception->getMessage() : 'Внутрішня помилка сервера. Спробуйте пізніше.'], JSON_UNESCAPED_UNICODE);
}
