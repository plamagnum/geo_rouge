class CartographerGame {
    constructor() { this.runState = null; this.currentEvent = null; }
    
    async startExpedition(studentName, grade, biomeId, seed = null) {
        const res = await fetch('/api.php?action=start', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ student_name: studentName, grade, biome_id: biomeId, seed })
        });
        this.runState = await res.json();
        this.renderHUD(); this.renderMap();
    }
    
    async triggerNode() {
        const res = await fetch(`/api.php?action=encounter&run_id=${this.runState.id}`);
        this.currentEvent = await res.json();
        this.renderEncounterModal(this.currentEvent);
    }
    
    async submitAnswer(chosenIndex) {
        const res = await fetch('/api.php?action=answer', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ run_id: this.runState.id, event_id: this.currentEvent.id, chosen_index: chosenIndex })
        });
        const result = await res.json();
        this.runState = result.run_state;
        this.showFeedback(result.is_correct, result.explanation);
        this.renderHUD(); this.renderMap();
    }
    
    renderHUD() {
        document.getElementById('hud-supplies').textContent = `⛺ Провізія: ${this.runState.supplies}%`;
        document.getElementById('hud-score').textContent = `🧭 Очки: ${this.runState.score}`;
        document.getElementById('hud-seed').textContent = `Код карти: ${this.runState.seed}`;
    }
    
    renderMap() {
        const mapContainer = document.getElementById('map-container');
        mapContainer.innerHTML = '';
        this.runState.map_graph.forEach((row, stepIndex) => {
            const rowEl = document.createElement('div');
            rowEl.className = `map-row ${stepIndex === this.runState.current_step ? 'active-row' : ''}`;
            row.forEach(node => {
                const btn = document.createElement('button');
                btn.className = `map-node type-${node.type}`;
                btn.disabled = (stepIndex !== this.runState.current_step || this.runState.status !== 'active');
                btn.textContent = { normal: '🧭', camp: '⛺', treasure: '⛏️', elite: '⚠️', boss: '🌪️' }[node.type] || '📍';
                btn.onclick = () => this.triggerNode();
                rowEl.appendChild(btn);
            });
            mapContainer.appendChild(rowEl);
        });
    }
    
    renderEncounterModal(event) {
        const modal = document.getElementById('encounter-modal');
        modal.innerHTML = `<h3>${event.title}</h3><p class="story">${event.story_text}</p><p class="question"><strong>${event.question_text}</strong></p><div class="options">${event.options.map((opt, idx) => `<button onclick="game.submitAnswer(${idx})">${opt}</button>`).join('')}</div>`;
        modal.style.display = 'block';
    }
    
    showFeedback(isCorrect, explanation) {
        const modal = document.getElementById('encounter-modal');
        modal.innerHTML = `<h3 style="color: ${isCorrect ? '#2e7d32' : '#c62828'}">${isCorrect ? '✅ Правильно!' : '❌ Помилка!'}</h3><p>${explanation}</p><button onclick="document.getElementById('encounter-modal').style.display='none'">Продовжити маршрут</button>`;
    }
}
const game = new CartographerGame();
