import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = {
        playing: Boolean,
        volume: Number
    }

    connect() {
        this.audio = this.element.querySelector('#audio-player');
        this.playPauseButton = this.element.querySelector('#play-pause-button');
        
        // Restaurer l'état si existant
        const wasPlaying = localStorage.getItem('audioWasPlaying') === 'true';
        const savedVolume = parseFloat(localStorage.getItem('audioVolume') || 1);

        if (this.audio) {
            this.audio.volume = savedVolume;
            if (wasPlaying) {
                this.audio.play();
            }
        }

        // Modifier l'état du bouton en fonction
        this._updatePlayPauseButton();

        // Écouter les événements de navigation
        document.documentElement.addEventListener('turbo:load', this._handleTurboLoad);
        document.documentElement.addEventListener('turbo:visit', this._handleTurboVisit);
    }

    disconnect() {
        document.documentElement.removeEventListener('turbo:load', this._handleTurboLoad);
        document.documentElement.removeEventListener('turbo:visit', this._handleTurboVisit);
    }

    _handleTurboVisit = () => {
        if (this.audio) {
            localStorage.setItem('audioWasPlaying', !this.audio.paused);
            localStorage.setItem('audioVolume', this.audio.volume);
        }
    }

    _handleTurboLoad = () => {
        // Réinitialiser la référence audio après la navigation
        this.audio = document.querySelector('#audio-player');
        if (this.audio && localStorage.getItem('audioWasPlaying') === 'true') {
            this.audio.volume = parseFloat(localStorage.getItem('audioVolume') || 1);
            this.audio.play();
        }
    }

    // Contrôle du bouton play/pause
    togglePlayPause() {
        if (this.audio.paused) {
            this.audio.play();
        } else {
            this.audio.pause();
        }

        // Mise à jour de l'état du bouton
        this._updatePlayPauseButton();
    }

    // Mise à jour du bouton play/pause
    _updatePlayPauseButton() {
        if (this.audio.paused) {
            this.playPauseButton.innerText = 'Lecture'; // ou tu peux mettre une icône "play"
        } else {
            this.playPauseButton.innerText = 'Pause'; // ou une icône "pause"
        }
    }
}
