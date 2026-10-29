import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['summary', 'timestamp', 'signal', 'button', 'notice', 'assets'];
    static values = { url: String };

    connect() {
        this.assetsTarget.textContent = 'CSS + JavaScript loaded ✓';
    }

    async refresh() {
        this.buttonTarget.disabled = true;
        this.noticeTarget.textContent = 'Running server checks…';
        try {
            const response = await fetch(this.urlValue, { cache: 'no-store', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(15000) });
            if (response.status !== 200 && response.status !== 503) throw new Error('Unexpected HTTP status');
            const report = await response.json();
            for (const [key, check] of Object.entries(report.checks)) {
                const card = this.element.querySelector(`[data-check="${key}"]`);
                if (!card) continue;
                const badge = card.querySelector('[data-field="status"]');
                badge.className = `badge ${check.status}`;
                badge.textContent = check.status === 'ok' ? 'PASS' : 'FAIL';
                card.querySelector('[data-field="detail"]').textContent = check.detail;
                card.querySelector('[data-field="duration"]').textContent = `${check.duration_ms} ms`;
            }
            this.signalTarget.className = `signal ${report.status}`;
            this.summaryTarget.textContent = report.status === 'ok' ? 'All server checks passed' : 'Some checks need attention';
            this.timestampTarget.textContent = report.checked_at;
            this.noticeTarget.textContent = 'Checks complete. Results updated.';
        } catch {
            this.noticeTarget.textContent = 'Could not complete checks. Reload the page or inspect the server logs.';
        } finally {
            this.buttonTarget.disabled = false;
        }
    }
}
