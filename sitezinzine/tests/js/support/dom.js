import { Application } from '@hotwired/stimulus'
import { vi } from 'vitest'

const applications = new Set()

export function element(data = {}, tag = 'div') {
    const node = document.createElement(tag)
    Object.assign(node.dataset, data)
    return node
}

export function jsonResponse(data, ok = true) {
    return { ok, status: ok ? 200 : 400, json: vi.fn().mockResolvedValue(data) }
}

export function deferred() {
    let resolve
    let reject
    const promise = new Promise((yes, no) => { resolve = yes; reject = no })
    return { promise, resolve, reject }
}

export async function flushDom() {
    // MutationObserver et continuations de promesses, sans attente arbitraire.
    for (let i = 0; i < 10; i++) await Promise.resolve()
}

export async function mountController(identifier, Controller, html) {
    document.body.innerHTML = html
    const application = new Application(document.documentElement)
    applications.add(application)
    application.register(identifier, Controller)
    await application.start()
    await flushDom()
    const root = document.querySelector(`[data-controller="${identifier}"]`)
    const controller = application.getControllerForElementAndIdentifier(root, identifier)
    if (!controller) throw new Error(`Contrôleur ${identifier} non connecté`)
    return { application, root, controller }
}

export async function disconnectControllers() {
    // Retirer le DOM avant stop déclenche réellement disconnect() dans Stimulus.
    document.body.replaceChildren()
    await flushDom()
    applications.forEach(application => application.stop())
    applications.clear()
}

export function mockNavigation() {
    const original = window
    const location = {
        origin: original.location.origin,
        pathname: original.location.pathname,
        search: original.location.search,
        href: original.location.href,
        reload: vi.fn(),
        assign: vi.fn(),
    }
    // jsdom 24 rend Location non configurable : remplacer seulement sa vue
    // via un proxy de window, sans altérer les objets DOM ni le code applicatif.
    vi.stubGlobal('window', new Proxy(original, {
        get(target, key) {
            if (key === 'location') return location
            const value = Reflect.get(target, key, target)
            return ['addEventListener', 'removeEventListener', 'dispatchEvent'].includes(key)
                ? value.bind(target)
                : value
        },
    }))
    return location
}
