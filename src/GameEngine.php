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
        $stmt = $this->db->prepare("SELECT grade FROM biomes WHERE id = :id");
        $stmt->execute([':id' => $biomeId]);
        $biome = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$biome) throw new DomainException('Біом не знайдено.');
        if ((int)$biome['grade'] !== $grade) throw new DomainException('Біом не відповідає обраному класу.');

        $seed = $customSeed !== null ? $customSeed : random_int(100000, 999999);
        $runId = bin2hex(random_bytes(16));
        $map = $this->generateMap($seed);

        $stmt = $this->db->prepare("INSERT INTO runs (id, student_name, grade, biome_id, seed, map_graph, inventory) VALUES (:id, :name, :grade, :biome, :seed, :map, :inv)");
        $stmt->execute([':id' => $runId, ':name' => $studentName, ':grade' => $grade, ':biome' => $biomeId, ':seed' => $seed, ':map' => json_encode($map), ':inv' => json_encode(['Компас'])]);
        return $this->getRunState($runId);
    }

    public function getEncounter(string $runId, string $nodeId): array {
        $this->db->beginTransaction();
        try {
            $run = $this->getActiveRun($runId);
            $step = (int)$run['current_step'];
            $nodeIndex = null;
            foreach ($run['map_graph'][$step] ?? [] as $index => $node) {
                if ($node['id'] === $nodeId) $nodeIndex = $index;
            }
            if ($nodeIndex === null) throw new DomainException('Оберіть вузол поточного кроку.');
            $node = $run['map_graph'][$step][$nodeIndex];
            if ($run['pending_event_id'] !== null && !$node['visited']) {
                throw new DomainException('Спочатку дайте відповідь на поточне питання.');
            }
            if ($node['visited'] && $run['pending_event_id'] === null) {
                throw new DomainException('Цей вузол уже відвідано.');
            }

            $run['map_graph'][$step][$nodeIndex]['visited'] = true;
            if ($node['type'] === 'camp' || $node['type'] === 'treasure') {
                if ($node['type'] === 'camp') {
                    $run['supplies'] = min(100, (int)$run['supplies'] + 25);
                    $message = '⛺ Відпочинок у таборі: +25 провізії (не більше 100).';
                } else {
                    $item = ['Барометр', 'Карта'][random_int(0, 1)];
                    $run['inventory'][] = $item;
                    $message = '⛏️ Знайдено предмет: ' . $item . '.';
                }
                $step++;
                $stmt = $this->db->prepare("UPDATE runs SET supplies = :sup, inventory = :inv, map_graph = :map, current_step = :step, status = :status WHERE id = :id");
                $stmt->execute([':sup' => $run['supplies'], ':inv' => json_encode($run['inventory']), ':map' => json_encode($run['map_graph']), ':step' => $step, ':status' => $step >= count($run['map_graph']) ? 'victory' : 'active', ':id' => $runId]);
                $result = ['node_type' => $node['type'], 'message' => $message, 'run_state' => $this->getRunState($runId)];
            } else {
                $fields = 'id, title, story_text, question_text, options, reward_xp, penalty_supplies';
                if ($run['pending_event_id'] !== null) {
                    $stmt = $this->db->prepare("SELECT $fields FROM events WHERE id = :id");
                    $stmt->execute([':id' => $run['pending_event_id']]);
                    $event = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $this->db->prepare("SELECT $fields FROM events WHERE biome_id = :biome AND id NOT IN (SELECT event_id FROM run_logs WHERE run_id = :run) ORDER BY (node_type = :type) DESC, RAND() LIMIT 1");
                    $stmt->execute([':biome' => $run['biome_id'], ':run' => $runId, ':type' => $node['type']]);
                    $event = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$event) {
                        $stmt = $this->db->prepare("SELECT $fields FROM events WHERE biome_id = :biome ORDER BY (node_type = :type) DESC, RAND() LIMIT 1");
                        $stmt->execute([':biome' => $run['biome_id'], ':type' => $node['type']]);
                        $event = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                }
                if (!$event) throw new DomainException('Для цього біому немає питань.');
                $multiplier = $node['type'] === 'elite' ? 2 : 1;
                $event['options'] = json_decode($event['options'], true);
                $event['reward_xp'] = (int)$event['reward_xp'] * $multiplier;
                $event['penalty_supplies'] = (int)$event['penalty_supplies'] * $multiplier;
                $stmt = $this->db->prepare("UPDATE runs SET pending_event_id = :event, map_graph = :map WHERE id = :id");
                $stmt->execute([':event' => $event['id'], ':map' => json_encode($run['map_graph']), ':id' => $runId]);
                $result = $event + ['node_type' => $node['type'], 'run_state' => $this->getRunState($runId)];
            }
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function submitAnswer(string $runId, int $eventId, int $chosenIndex): array {
        $this->db->beginTransaction();
        try {
            $run = $this->getActiveRun($runId);
            if ($run['pending_event_id'] === null || (int)$run['pending_event_id'] !== $eventId) {
                throw new DomainException('Це питання не було видано для цього забігу.');
            }
            $stmt = $this->db->prepare("SELECT * FROM events WHERE id = :id");
            $stmt->execute([':id' => $eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$event) throw new DomainException('Питання не знайдено.');
            $options = json_decode($event['options'], true);
            if ($chosenIndex < 0 || $chosenIndex >= count($options)) {
                throw new DomainException('Оберіть один із запропонованих варіантів.');
            }

            $multiplier = 1;
            foreach ($run['map_graph'][(int)$run['current_step']] as $node) {
                if ($node['visited'] && $node['type'] === 'elite') $multiplier = 2;
            }
            $isCorrect = ((int)$event['correct_index'] === $chosenIndex);
            $supplies = (int)$run['supplies'];
            $score = (int)$run['score'];
            $step = (int)$run['current_step'] + 1;

            if ($isCorrect) $score += (int)$event['reward_xp'] * $multiplier;
            else $supplies = max(0, $supplies - (int)$event['penalty_supplies'] * $multiplier);

            $status = 'active';
            if ($supplies <= 0) $status = 'defeat';
            elseif ($step >= count($run['map_graph'])) $status = 'victory';

            $stmt = $this->db->prepare("INSERT INTO run_logs (run_id, event_id, chosen_index, is_correct) VALUES (:run, :event, :chosen, :correct)");
            $stmt->execute([':run' => $runId, ':event' => $eventId, ':chosen' => $chosenIndex, ':correct' => (int)$isCorrect]);
            $upd = $this->db->prepare("UPDATE runs SET supplies = :sup, score = :score, current_step = :step, status = :status, pending_event_id = NULL WHERE id = :id");
            $upd->execute([':sup' => $supplies, ':score' => $score, ':step' => $step, ':status' => $status, ':id' => $runId]);

            $result = ['is_correct' => $isCorrect, 'correct_index' => (int)$event['correct_index'], 'explanation' => $event['explanation'], 'run_state' => $this->getRunState($runId)];
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function getActiveRun(string $runId): array {
        $run = $this->getRunState($runId, true);
        if (!$run) throw new DomainException('Забіг не знайдено.');
        if ($run['status'] !== 'active') throw new DomainException('Цей забіг уже завершено.');
        return $run;
    }

    public function getRunState(string $runId, bool $lock = false): array {
        $stmt = $this->db->prepare("SELECT * FROM runs WHERE id = :id" . ($lock ? " FOR UPDATE" : ""));
        $stmt->execute([':id' => $runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($run) {
            $run['map_graph'] = json_decode($run['map_graph'], true);
            $run['inventory'] = json_decode($run['inventory'], true);
        }
        return $run ?: [];
    }
}
