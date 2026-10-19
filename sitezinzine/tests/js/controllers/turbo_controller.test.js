import { describe, expect, test, vi } from 'vitest'
import TurboController from '../../../assets/controllers/turbo_controller'
import { mountController, flushDom } from '../support/dom'

function mockAudio() {
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function () {
        Object.defineProperty(this, 'paused', { configurable: true, value: false })
        return Promise.resolve()
    })
    vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(function () {
        Object.defineProperty(this, 'paused', { configurable: true, value: true })
    })
}

const markup = '<div data-controller="turbo"><audio id="audio-player"></audio><button id="play-pause-button" data-action="click->turbo#togglePlayPause"></button></div>'

describe('État audio au cours de la navigation Turbo', () => {
    test('connect restaure lecture et volume sans exécuter de lecteur réel', async () => {
        mockAudio(); localStorage.setItem('audioWasPlaying', 'true'); localStorage.setItem('audioVolume', '0.25')
        const { controller } = await mountController('turbo', TurboController, markup)
        expect(controller.audio.volume).toBe(0.25)
        expect(controller.audio.play).toHaveBeenCalledOnce()
        expect(controller.playPauseButton.innerText).toBe('Pause')
    })

    test('le bouton alterne lecture et pause avec mise à jour du libellé', async () => {
        mockAudio()
        const { controller } = await mountController('turbo', TurboController, markup)
        controller.playPauseButton.click()
        expect(controller.audio.play).toHaveBeenCalledOnce()
        expect(controller.playPauseButton.innerText).toBe('Pause')
        controller.playPauseButton.click()
        expect(controller.audio.pause).toHaveBeenCalledOnce()
        expect(controller.playPauseButton.innerText).toBe('Lecture')
    })

    test('turbo:visit sauvegarde l’état et turbo:load retrouve le nouvel audio', async () => {
        mockAudio()
        const { controller } = await mountController('turbo', TurboController, markup)
        controller.audio.volume = 0.4
        await controller.audio.play()
        document.documentElement.dispatchEvent(new Event('turbo:visit'))
        expect(localStorage.getItem('audioWasPlaying')).toBe('true')
        expect(localStorage.getItem('audioVolume')).toBe('0.4')
        const replacement = document.createElement('audio'); replacement.id = 'audio-player'
        controller.audio.replaceWith(replacement)
        document.documentElement.dispatchEvent(new Event('turbo:load'))
        expect(controller.audio).toBe(replacement)
        expect(replacement.volume).toBe(0.4)
        expect(replacement.paused).toBe(false)
    })

    test('les événements de navigation n’agissent plus après disconnect', async () => {
        mockAudio()
        const { root } = await mountController('turbo', TurboController, markup)
        root.remove(); await flushDom()
        document.documentElement.dispatchEvent(new Event('turbo:visit'))
        expect(localStorage.getItem('audioWasPlaying')).toBeNull()
    })
})
