<?php
class GameEngine {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function generateMap(int $seed, int $steps = 6): array {
        mt_srand($seed);
        $types = ['normal', 'normal', 'camp', 'treasure', 'elite'];
        $map = [];

        for ($step = 0; $step < $steps; $step++) {
            $nodesInRow = ($step === 0 || $step === $steps - 1) ? 1 : mt_rand(2, 3);
            $row = [];
            for ($n = 0; $n < $nodesInRow; $n++) {
                if ($step === 0) $type = 'normal';
                elseif ($step === $steps - 1) $type = 'boss';
                else $type = $types[mt_rand(0, count($types) - 1)];

                $row[] = ['id' => "s{$step}_n{$n}", 'step' => $step, 'type' => $type, 'visited' => false];
            }
            $map[] = $row;
        }
        mt_srand();
        return $map;
    }

    public function startRun(string $studentName, int $grade, int $biomeId, ?int $customSeed = null): array {
        $seed = $customSeed ?: random_int(100000, 999999);
        $runId = bin2hex(random_bytes(16));
        $map = $this->generateMap($seed);

        $stmt = $this->db->prepare("INSERT INTO runs (id, student_name, grade, biome_id, seed, map_graph, inventory) VALUES (:id, :name, :grade, :biome, :seed, :map, :inv)");
        $stmt->execute([':id' => $runId, ':name' => $studentName, ':grade' => $grade, ':biome' => $biomeId, ':seed' => $seed, ':map' => json_encode($map), ':inv' => json_encode(['Компас'])]);
        return $this->getRunState($runId);
    }

    public function getEncounter(string $runId): array {
        $run = $this->getRunState($runId);
        $stmt = $this->db->prepare("SELECT id, title, story_text, question_text, options, reward_xp, penalty_supplies FROM events WHERE biome_id = :biome ORDER BY RAND() LIMIT 1");
        $stmt->execute([':biome' => $run['biome_id']]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        $event['options'] = json_decode($event['options'], true);
        return $event;
    }

    public function submitAnswer(string $runId, int $eventId, int $chosenIndex): array {
        $run = $this->getRunState($runId);
        $stmt = $this->db->prepare("SELECT * FROM events WHERE id = :id");
        $stmt->execute([':id' => $eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        $isCorrect = ((int)$event['correct_index'] === $chosenIndex);
        $supplies = (int)$run['supplies'];
        $score = (int)$run['score'];
        $step = (int)$run['current_step'] + 1;

        if ($isCorrect) $score += (int)$event['reward_xp'];
        else $supplies = max(0, $supplies - (int)$event['penalty_supplies']);

        $status = 'active';
        if ($supplies <= 0) $status = 'defeat';
        elseif ($step >= count($run['map_graph'])) $status = 'victory';

        $upd = $this->db->prepare("UPDATE runs SET supplies = :sup, score = :score, current_step = :step, status = :status WHERE id = :id");
        $upd->execute([':sup' => $supplies, ':score' => $score, ':step' => $step, ':status' => $status, ':id' => $runId]);

        return ['is_correct' => $isCorrect, 'correct_index' => (int)$event['correct_index'], 'explanation' => $event['explanation'], 'run_state' => $this->getRunState($runId)];
    }

    public function getRunState(string $runId): array {
        $stmt = $this->db->prepare("SELECT * FROM runs WHERE id = :id");
        $stmt->execute([':id' => $runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($run) {
            $run['map_graph'] = json_decode($run['map_graph'], true);
            $run['inventory'] = json_decode($run['inventory'], true);
        }
        return $run ?: [];
    }
}
