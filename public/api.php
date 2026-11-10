<?php
header('Content-Type: application/json; charset=utf-8');

try {
    $action = $_GET['action'] ?? '';
    $body = file_get_contents('php://input');
    $input = $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if ($input === null) $input = [];
    if (!is_array($input)) throw new DomainException('Некоректні дані запиту.');

    $integer = function ($value): int {
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if ($result === false) throw new DomainException('Очікується ціле число.');
        return $result;
    };
    if (!in_array($action, ['start', 'encounter', 'answer', 'state'], true)) {
        throw new DomainException('Невідома дія.');
    }
    $runId = in_array($action, ['encounter', 'state'], true) ? ($_GET['run_id'] ?? '') : ($input['run_id'] ?? '');
    if ($action !== 'start' && (!is_string($runId) || $runId === '')) {
        throw new DomainException('Потрібно вказати run_id.');
    }
    $nodeId = $_GET['node_id'] ?? '';
    if ($action === 'encounter' && (!is_string($nodeId) || $nodeId === '')) {
        throw new DomainException('Потрібно вказати node_id.');
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_NAME'));
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    require_once __DIR__ . '/../src/GameEngine.php';
    $engine = new GameEngine($pdo);

    switch ($action) {
        case 'start':
            $name = $input['student_name'] ?? 'Дослідник';
            if (!is_string($name) || preg_match('/^.{0,100}$/usD', $name) !== 1) {
                throw new DomainException('Ім’я дослідника має містити не більше 100 символів.');
            }
            echo json_encode($engine->startRun($name, $integer($input['grade'] ?? 8), $integer($input['biome_id'] ?? 2), ($input['seed'] ?? null) !== null ? $integer($input['seed'] ?? null) : null));
            break;
        case 'encounter':
            echo json_encode($engine->getEncounter($runId, $nodeId));
            break;
        case 'answer':
            if (($input['event_id'] ?? null) === null || ($input['chosen_index'] ?? null) === null) {
                throw new DomainException('Потрібно вказати питання та варіант відповіді.');
            }
            echo json_encode($engine->submitAnswer($runId, $integer($input['event_id'] ?? null), $integer($input['chosen_index'] ?? null)));
            break;
        case 'state':
            $run = $engine->getRunState($runId);
            if (!$run) throw new DomainException('Забіг не знайдено.');
            echo json_encode($run);
            break;
    }
} catch (Throwable $e) {
    $domainError = $e instanceof DomainException || $e instanceof JsonException;
    http_response_code($domainError ? 400 : 500);
    echo json_encode(['error' => $domainError ? $e->getMessage() : 'Внутрішня помилка сервера. Спробуйте пізніше.']);
}
