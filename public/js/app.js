class CartographerGame {
    constructor() {
        this.runState = null; this.currentEvent = null; this.busy = false;
        this.user = null; this.csrf = null; this.catalog = [];
    }

    async request(url, options = {}) {
        if (options.method === 'POST') {
            options.headers = { ...options.headers, 'X-CSRF-Token': this.csrf || '' };
        }
        const res = await fetch(url, { ...options, credentials: 'same-origin', cache: 'no-store' });
        const result = await res.json().catch(() => ({
            error: res.status === 429 ? 'Забагато спроб. Зачекайте хвилину й повторіть.' : 'Сервер тимчасово недоступний.'
        }));
        if (!res.ok || result.error) {
            if (res.status === 401 || res.status === 403) {
                try { await this.refreshSession(); } catch { /* Повторне з’єднання доступне після оновлення сторінки. */ }
            }
            throw new Error(result.error || 'Не вдалося виконати запит.');
        }
        return result;
    }

    post(action, input = {}) {
        return this.request(`/api.php?action=${action}`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input)
        });
    }

    async refreshSession() {
        const session = await this.request('/api.php?action=session');
        const changed = this.user?.id !== session.user?.id;
        this.csrf = session.csrf;
        this.user = session.user;
        if (changed || !this.user) {
            this.runState = null; this.currentEvent = null;
            document.getElementById('map-container').style.display = 'none';
            document.getElementById('encounter-modal').style.display = 'none';
            document.getElementById('hud-supplies').textContent = '⛺ Провізія: —';
            document.getElementById('hud-score').textContent = '🧭 Очки: —';
            document.getElementById('hud-seed').textContent = 'Код карти: —';
        }
        document.getElementById('login-screen').hidden = !!this.user;
        document.getElementById('setup-screen').hidden = !this.user || !!this.runState;
        document.getElementById('student-controls').hidden = !this.user;
        document.getElementById('login-button').disabled = false;
        document.getElementById('session-retry').hidden = true;
        document.getElementById('session-status').textContent = this.user
            ? `${this.user.display_name} · ${this.user.grade} клас`
            : 'Увійдіть, щоб розпочати експедицію.';
    }

    async initialize() {
        try {
            await this.refreshSession();
            if (this.user) await this.loadCatalog();
        } catch (error) {
            document.getElementById('login-screen').hidden = false;
            document.getElementById('session-retry').hidden = false;
            document.getElementById('session-status').textContent = error.message;
        }
    }

    async login(login, password) {
        if (this.busy) return;
        this.busy = true;
        try {
            await this.post('login', { login, password });
            document.getElementById('student-password').value = '';
            await this.refreshSession();
            await this.loadCatalog();
        } catch (error) {
            this.showError(error);
        } finally {
            document.getElementById('student-password').value = '';
            this.busy = false;
        }
    }

    async logout() {
        if (this.busy) return;
        this.busy = true;
        try {
            await this.post('logout');
            await this.refreshSession();
            this.catalog = [];
            document.getElementById('biome-select').replaceChildren();
            document.getElementById('biome-description').textContent = '';
        } catch (error) {
            this.showError(error);
        } finally { this.busy = false; }
    }

    async loadCatalog() {
        const select = document.getElementById('biome-select');
        const status = document.getElementById('catalog-status');
        select.replaceChildren(); select.disabled = true;
        document.getElementById('start-button').disabled = true;
        document.getElementById('biome-description').textContent = '';
        status.textContent = 'Завантаження біомів…';
        try {
            const catalog = await this.request('/api.php?action=catalog');
            this.catalog = catalog.filter(biome => Number(biome.grade) === this.user.grade);
            this.catalog.forEach(biome => {
                const option = document.createElement('option');
                option.value = biome.id;
                option.disabled = Number(biome.event_count) === 0;
                option.textContent = `${biome.title}${option.disabled ? ' — питання ще не додано' : ''}`;
                select.appendChild(option);
            });
            const available = this.catalog.find(biome => Number(biome.event_count) > 0);
            select.disabled = !available;
            if (available) select.value = String(available.id);
            status.textContent = !this.catalog.length ? 'Для вашого класу біомів ще немає. Зверніться до адміністратора.'
                : !available ? 'Біоми ще не мають питань. Адміністратор має додати їх у панелі.' : '';
            this.updateBiomeDescription();
        } catch (error) {
            status.textContent = error.message + ' Натисніть «Вибрати експедицію», щоб повторити.';
        }
    }

    updateBiomeDescription() {
        const biome = this.catalog.find(item => String(item.id) === document.getElementById('biome-select').value);
        document.getElementById('biome-description').textContent = biome?.description || '';
        document.getElementById('start-button').disabled = !biome || Number(biome.event_count) === 0;
    }

    async chooseExpedition() {
        if (this.busy || !this.user) return;
        this.busy = true;
        this.runState = null; this.currentEvent = null;
        document.getElementById('map-container').style.display = 'none';
        document.getElementById('encounter-modal').style.display = 'none';
        document.getElementById('setup-screen').hidden = false;
        try { await this.loadCatalog(); } finally { this.busy = false; }
    }

    showError(error) {
        alert(`Помилка: ${error.message || 'Не вдалося з’єднатися із сервером.'}`);
    }

    async recoverState() {
        try {
            this.runState = await this.request(`/api.php?action=state&run_id=${encodeURIComponent(this.runState.id)}`);
            this.currentEvent = null;
            document.getElementById('encounter-modal').style.display = 'none';
            this.renderHUD(); this.renderMap();
            if (this.runState.pending_event_id !== null) {
                const node = this.runState.map_graph[this.runState.current_step].find(node => node.visited);
                this.currentEvent = await this.post('encounter', { run_id: this.runState.id, node_id: node.id });
                this.renderEncounterModal(this.currentEvent);
            }
        } catch {
            // Залишаємо останній стан, якщо з’єднання ще не відновлено.
        }
    }
    
    async startExpedition(biomeId, seed = null) {
        if (this.busy) return false;
        this.busy = true;
        try {
            this.runState = await this.post('start', { biome_id: biomeId, seed });
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
            const result = await this.post('encounter', { run_id: this.runState.id, node_id: nodeId });
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
            await this.recoverState();
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
            await this.recoverState();
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
document.getElementById('login-form').addEventListener('submit', event => {
    event.preventDefault();
    game.login(document.getElementById('student-login').value, document.getElementById('student-password').value);
});
document.getElementById('logout-button').addEventListener('click', () => game.logout());
document.getElementById('choose-expedition').addEventListener('click', () => game.chooseExpedition());
document.getElementById('session-retry').addEventListener('click', () => game.initialize());
document.getElementById('biome-select').addEventListener('change', () => game.updateBiomeDescription());
document.getElementById('start-button').addEventListener('click', async () => {
    const seedInput = document.getElementById('custom-seed');
    if (!seedInput.reportValidity()) return;
    const seed = seedInput.value === '' ? null : Number(seedInput.value);
    if (await game.startExpedition(Number(document.getElementById('biome-select').value), seed)) {
        document.getElementById('setup-screen').hidden = true;
        document.getElementById('map-container').style.display = 'flex';
    }
});
game.initialize();
