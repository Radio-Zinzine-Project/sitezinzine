import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'emission'];

    

    connect() {
        this.updateVisibleEmissions();
        console.log("📡 Stimulus controller 'theme-filter' connecté !");
    }

    toggleFilter(event) {
        const button = event.currentTarget;
        button.classList.toggle('active');
        this.updateVisibleEmissions();
    }

    updateVisibleEmissions() {
        const activeThemeIds = this.buttonTargets
            .filter(btn => btn.classList.contains('active'))
            .map(btn => btn.dataset.themeId);

        this.emissionTargets.forEach(emission => {
            const show = activeThemeIds.length === 0 || activeThemeIds.includes(emission.dataset.themeId);
            emission.style.display = show ? 'flex' : 'none';
        });
    }
}
