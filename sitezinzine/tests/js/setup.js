import { afterEach, beforeEach, expect, vi } from 'vitest'
import { disconnectControllers } from './support/dom'

let unexpectedRequests

beforeEach(() => {
    unexpectedRequests = []
    history.replaceState(null, '', '/')
    vi.stubGlobal('fetch', vi.fn(() => {
        unexpectedRequests.push('fetch')
        throw new Error('Réseau interdit en test : configurer une réponse fetch simulée')
    }))
    vi.stubGlobal('XMLHttpRequest', class {
        constructor() {
            unexpectedRequests.push('XMLHttpRequest')
            throw new Error('XHR réel interdit en test')
        }
    })
    vi.spyOn(window, 'alert').mockImplementation(() => {})
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    vi.spyOn(console, 'log').mockImplementation(() => {})
})

afterEach(async () => {
    // Fermer les modales qui enregistrent un listener keydown global.
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await disconnectControllers()
    localStorage.clear()
    sessionStorage.clear()
    document.body.removeAttribute('style')
    document.documentElement.removeAttribute('style')
    if (vi.isFakeTimers()) vi.clearAllTimers()
    vi.useRealTimers()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    expect(unexpectedRequests, 'Toute requête doit avoir une réponse simulée explicite').toEqual([])
})
