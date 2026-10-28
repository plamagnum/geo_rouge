SET NAMES utf8mb4;

CREATE TABLE biomes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade TINYINT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    climate_modifiers JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    biome_id INT NOT NULL,
    node_type ENUM('normal', 'elite', 'boss') DEFAULT 'normal',
    title VARCHAR(150) NOT NULL,
    story_text TEXT NOT NULL,
    question_text TEXT NOT NULL,
    options JSON NOT NULL,
    correct_index TINYINT NOT NULL,
    reward_xp INT DEFAULT 25,
    penalty_supplies INT DEFAULT 15,
    explanation TEXT NOT NULL,
    FOREIGN KEY (biome_id) REFERENCES biomes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE runs (
    id VARCHAR(36) PRIMARY KEY,
    student_name VARCHAR(100) NOT NULL,
    grade TINYINT NOT NULL,
    biome_id INT NOT NULL,
    seed INT NOT NULL,
    supplies INT DEFAULT 100,
    equipment INT DEFAULT 100,
    score INT DEFAULT 0,
    current_step INT DEFAULT 0,
    map_graph JSON NOT NULL,
    inventory JSON NOT NULL,
    status ENUM('active', 'victory', 'defeat') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (biome_id) REFERENCES biomes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE run_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(36) NOT NULL,
    event_id INT NOT NULL,
    chosen_index TINYINT NOT NULL,
    is_correct BOOLEAN NOT NULL,
    answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO biomes (grade, title, description, climate_modifiers) VALUES
(7, 'Перетин Анд і Амазонії', 'Висотний пояс Анд та вологі ліси.', '{"humidity": "extreme", "base_supply_cost": 5}'),
(8, 'Український щит та Степ', 'Геологічна будова та південь України.', '{"drought_risk": "high", "base_supply_cost": 4}');

INSERT INTO events (biome_id, node_type, title, story_text, question_text, options, correct_index, reward_xp, penalty_supplies, explanation) VALUES
(1, 'normal', 'Східні схили Анд', 'Біля підніжжя (500 м) температура +24°C. Ви піднімаєтесь на перевал 4500 м.', 'Яка температура очікує групу на перевалі?', '["+12°C", "0°C", "-12°C"]', 1, 30, 20, 'З підняттям на кожні 1000 м температура падає на 6°C. Різниця висот: 4500 - 500 = 4000 м (4 км * 6 = 24). Отже, 24 - 24 = 0°C.'),
(2, 'normal', 'Виходи кристалічних порід', 'У долині ви натрапили на виходи гранітів.', 'Яка тектонічна структура лежить в основі?', '["Западина", "Кристалічний щит", "Плита"]', 1, 30, 15, 'Український щит — це піднята ділянка фундаменту платформи.');
