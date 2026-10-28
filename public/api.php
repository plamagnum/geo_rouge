<?php
header('Content-Type: application/json; charset=utf-8');

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_NAME'));
$pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

require_once __DIR__ . '/../src/GameEngine.php';
$engine = new GameEngine($pdo);

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    case 'start':
        echo json_encode($engine->startRun($input['student_name'] ?? 'Дослідник', (int)($input['grade'] ?? 8), (int)($input['biome_id'] ?? 2), !empty($input['seed']) ? (int)$input['seed'] : null));
        break;
    case 'encounter':
        echo json_encode($engine->getEncounter($_GET['run_id'] ?? ''));
        break;
    case 'answer':
        echo json_encode($engine->submitAnswer($input['run_id'], (int)$input['event_id'], (int)$input['chosen_index']));
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
