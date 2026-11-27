// assets/themeFilter.js
function initThemeFilter() {
    const buttons = document.querySelectorAll('.theme-card-button');
    const emissions = document.querySelectorAll('.emission-row');

    buttons.forEach(button => {
        button.addEventListener('click', () => {
            const themeId = button.dataset.themeId;

            // Toggle l'état actif du bouton
            button.classList.toggle('active');

            // Récupère tous les boutons actifs
            const activeThemeIds = Array.from(buttons)
                .filter(btn => btn.classList.contains('active'))
                .map(btn => btn.dataset.themeId);

            // Si aucun bouton n'est actif → on affiche tout
            if (activeThemeIds.length === 0) {
                emissions.forEach(emission => {
                    emission.style.display = 'flex';
                });
            } else {
                emissions.forEach(emission => {
                    if (activeThemeIds.includes(emission.dataset.themeId)) {
                        emission.style.display = 'flex';
                    } else {
                        emission.style.display = 'none';
                    }
                });
            }
        });
    });
}

// Bind via turbo:load
document.addEventListener('turbo:load', initThemeFilter);
