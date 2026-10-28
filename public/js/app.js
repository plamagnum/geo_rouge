class CartographerGame {
    constructor() { this.runState = null; this.currentEvent = null; this.busy = false; }

    async request(url, options = {}) {
        const res = await fetch(url, options);
        const result = await res.json();
        if (!res.ok || result.error) throw new Error(result.error || 'Не вдалося виконати запит.');
        return result;
    }

    showError(error) {
        alert(`Помилка: ${error.message || 'Не вдалося з’єднатися із сервером.'}`);
    }
    
    async startExpedition(studentName, grade, biomeId, seed = null) {
        if (this.busy) return false;
        this.busy = true;
        try {
            this.runState = await this.request('/api.php?action=start', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_name: studentName, grade, biome_id: biomeId, seed })
            });
            this.currentEvent = null;
            document.getElementById('encounter-modal').style.display = 'none';
            this.renderHUD(); this.renderMap();
            return true;
        } catch (error) {
            this.showError(error);
            return false;
        } finally {
            this.busy = false;
        }
    }
    
    async triggerNode(nodeId) {
        if (this.busy) return;
        this.busy = true;
        try {
            const result = await this.request(`/api.php?action=encounter&run_id=${encodeURIComponent(this.runState.id)}&node_id=${encodeURIComponent(nodeId)}`);
            this.runState = result.run_state;
            if (result.message) {
                this.currentEvent = null;
                this.showFeedback(true, result.message, 'Знахідка на маршруті');
            } else {
                this.currentEvent = result;
                this.renderEncounterModal(result);
            }
            this.renderHUD(); this.renderMap();
        } catch (error) {
            this.showError(error);
        } finally {
            this.busy = false;
        }
    }
    
    async submitAnswer(chosenIndex) {
        if (this.busy || !this.currentEvent) return;
        this.busy = true;
        try {
            const result = await this.request('/api.php?action=answer', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ run_id: this.runState.id, event_id: this.currentEvent.id, chosen_index: chosenIndex })
            });
            this.runState = result.run_state;
            this.currentEvent = null;
            this.showFeedback(result.is_correct, result.explanation);
            this.renderHUD(); this.renderMap();
        } catch (error) {
            this.showError(error);
        } finally {
            this.busy = false;
        }
    }
    
    renderHUD() {
        document.getElementById('hud-supplies').textContent = `⛺ Провізія: ${this.runState.supplies}%`;
        document.getElementById('hud-score').textContent = `🧭 Очки: ${this.runState.score}`;
        document.getElementById('hud-seed').textContent = `Код карти: ${this.runState.seed}`;
    }
    
    renderMap() {
        const mapContainer = document.getElementById('map-container');
        mapContainer.replaceChildren();
        this.runState.map_graph.forEach((row, stepIndex) => {
            const rowEl = document.createElement('div');
            rowEl.className = `map-row ${stepIndex === this.runState.current_step ? 'active-row' : ''}`;
            row.forEach(node => {
                const btn = document.createElement('button');
                btn.className = `map-node type-${node.type}`;
                if (node.visited) btn.style.borderColor = '#2e7d32';
                btn.disabled = (stepIndex !== this.runState.current_step || this.runState.status !== 'active');
                btn.textContent = { normal: '🧭', camp: '⛺', treasure: '⛏️', elite: '⚠️', boss: '🌪️' }[node.type] || '📍';
                btn.addEventListener('click', () => this.triggerNode(node.id));
                rowEl.appendChild(btn);
            });
            mapContainer.appendChild(rowEl);
        });
    }
    
    renderEncounterModal(event) {
        const modal = document.getElementById('encounter-modal');
        modal.replaceChildren();
        const title = document.createElement('h3');
        title.textContent = event.title;
        const story = document.createElement('p');
        story.className = 'story';
        story.textContent = event.story_text;
        const question = document.createElement('p');
        question.className = 'question';
        const strong = document.createElement('strong');
        strong.textContent = event.question_text;
        question.appendChild(strong);
        const options = document.createElement('div');
        options.className = 'options';
        event.options.forEach((option, index) => {
            const button = document.createElement('button');
            button.textContent = option;
            button.addEventListener('click', () => this.submitAnswer(index));
            options.appendChild(button);
        });
        modal.append(title, story, question, options);
        modal.style.display = 'block';
    }
    
    showFeedback(isCorrect, explanation, heading = null) {
        const modal = document.getElementById('encounter-modal');
        modal.replaceChildren();
        const title = document.createElement('h3');
        title.style.color = isCorrect ? '#2e7d32' : '#c62828';
        title.textContent = heading || (isCorrect ? '✅ Правильно!' : '❌ Помилка!');
        const text = document.createElement('p');
        text.textContent = explanation;
        const button = document.createElement('button');
        button.textContent = 'Продовжити маршрут';
        button.addEventListener('click', () => { modal.style.display = 'none'; });
        modal.append(title, text, button);
        modal.style.display = 'block';
    }
}
const game = new CartographerGame();
