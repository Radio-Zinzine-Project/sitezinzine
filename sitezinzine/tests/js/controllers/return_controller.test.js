import { describe, expect, test, vi } from 'vitest'
import * as Turbo from '@hotwired/turbo'
import ReturnController from '../../../assets/controllers/return_controller'
import { mountController } from '../support/dom'

vi.mock('@hotwired/turbo', () => ({ visit: vi.fn() }))

describe('Navigation avec retour', () => {
    test('le lien transmet le chemin et les filtres à Turbo.visit', async () => {
        history.replaceState(null, '', '/admin/emission/?page=2&q=été')
        const { root } = await mountController('return', ReturnController,
            '<a data-controller="return" data-return-url-value="/admin/emission/42/edit" data-action="click->return#redirectWithReturnTo">Modifier</a>')
        const click = new MouseEvent('click', { bubbles: true, cancelable: true })
        root.dispatchEvent(click)
        expect(click.defaultPrevented).toBe(true)
        const target = new URL(Turbo.visit.mock.calls[0][0], window.location.origin)
        expect(target.pathname).toBe('/admin/emission/42/edit')
        expect(target.searchParams.get('returnTo')).toBe(window.location.pathname + window.location.search)
    })

    test.each([false, true])('confirmation %s : seule une annulation bloque submit', async confirmed => {
        window.confirm.mockReturnValue(confirmed)
        const { root } = await mountController('return', ReturnController,
            '<form data-controller="return" data-action="submit->return#confirmBeforeSubmit"></form>')
        const submit = new Event('submit', { bubbles: true, cancelable: true })
        root.dispatchEvent(submit)
        expect(submit.defaultPrevented).toBe(!confirmed)
        expect(Turbo.visit).not.toHaveBeenCalled()
    })
})
