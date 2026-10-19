import { describe, expect, test, vi } from 'vitest'
import GridController from '../../../assets/controllers/grid_controller'
import { mountController, flushDom } from '../support/dom'

function gridMarkup(readonly = false) {
    return `<div data-controller="grid" data-grid-readonly-value="${readonly}" data-grid-week-start-value="2026-10-06">
        <button data-grid-target="modeRegularBtn" data-action="click->grid#showRegularMode"></button>
        <button data-grid-target="modeSpecialBtn" data-action="click->grid#showSpecialMode"></button>
        <div data-grid-target="regularPanel"><input data-grid-target="regularSearch" data-action="input->grid#regularSearch"><div data-grid-target="emissionsList"></div></div>
        <div data-grid-target="specialPanel">
            <input data-grid-target="specialSearch"><button data-grid-target="specialShowAllBtn"></button>
            <div data-grid-target="specialStatus"></div><div data-grid-target="specialEmptyState"></div>
            <div data-grid-target="specialSidebarPanel"></div><div data-grid-target="emissionsList"></div>
        </div>
        <div class="day-col" data-grid-target="day" data-day-index="0">
            <div class="postit" data-is-manual-draft="true" draggable="true"></div>
        </div>
    </div>`
}

describe('Grille avec un vrai cycle Stimulus', () => {
    test('readonly désactive le drag & drop au connect et bloque les mutations publiques', async () => {
        const { controller, root } = await mountController('grid', GridController, gridMarkup(true))
        const postit = root.querySelector('.postit'); const day = root.querySelector('.day-col')
        expect(postit.draggable).toBe(false)
        const dragover = new Event('dragover', { cancelable: true }); day.dispatchEvent(dragover)
        expect(dragover.defaultPrevented).toBe(false)
        expect(day.classList.contains('drag-over')).toBe(false)
        for (const method of ['createLiveEmission', 'removeAssignment', 'selectEmission', 'submitWeekReschedule', 'submitCustomReschedule', 'cancelOccurrence', 'restoreOccurrence', 'clearReschedule']) {
            await controller[method]()
        }
        expect(fetch).not.toHaveBeenCalled()
        expect(window.confirm).not.toHaveBeenCalled()
    })

    test('les actions Stimulus changent les panneaux et mémorisent le mode', async () => {
        const { controller, root } = await mountController('grid', GridController, gridMarkup())
        root.querySelector('[data-grid-target="modeSpecialBtn"]').click()
        await flushDom()
        expect(controller.currentMode).toBe('special')
        expect(controller.regularPanelTarget.style.display).toBe('none')
        expect(controller.specialPanelTarget.style.display).toBe('block')
        expect(controller.modeSpecialBtnTarget.getAttribute('aria-pressed')).toBe('true')
        expect(sessionStorage.getItem('gridCurrentMode')).toBe('special')
        root.querySelector('[data-grid-target="modeRegularBtn"]').click()
        expect(controller.currentMode).toBe('regular')
        expect(controller.modeSpecialBtnTarget.getAttribute('aria-pressed')).toBe('false')
    })

    test('les frappes successives déclenchent une seule recherche après 300 ms', async () => {
        const { controller } = await mountController('grid', GridController, gridMarkup())
        vi.useFakeTimers()
        const load = vi.spyOn(controller, 'loadCandidatesForSelectedPostit').mockResolvedValue()
        controller.regularSearchTarget.dispatchEvent(new Event('input', { bubbles: true }))
        vi.advanceTimersByTime(200)
        controller.regularSearchTarget.dispatchEvent(new Event('input', { bubbles: true }))
        vi.advanceTimersByTime(299)
        expect(load).not.toHaveBeenCalled()
        await vi.advanceTimersByTimeAsync(1)
        expect(load).toHaveBeenCalledOnce()
        expect(vi.getTimerCount()).toBe(0)
    })

    test('déconnecter retire le listener resize', async () => {
        const remove = vi.spyOn(window, 'removeEventListener')
        const { controller, root } = await mountController('grid', GridController, gridMarkup())
        root.remove(); await flushDom()
        expect(remove).toHaveBeenCalledWith('resize', controller.syncSidebarHeight)
    })

    test('déconnecter annule les recherches différées encore en attente', async () => {
        vi.useFakeTimers()

        const { controller, root } = await mountController(
            'grid',
            GridController,
            gridMarkup()
        )

        const loadRegular = vi
            .spyOn(controller, 'loadCandidatesForSelectedPostit')
            .mockResolvedValue()

        const loadSpecial = vi
            .spyOn(controller, 'loadSpecialCandidates')
            .mockResolvedValue()

        controller.regularSearch()
        controller.specialSearch()

        root.remove()
        await flushDom()

        await vi.advanceTimersByTimeAsync(300)

        expect(loadRegular).not.toHaveBeenCalled()
        expect(loadSpecial).not.toHaveBeenCalled()
    })

    test('reconnecter le contrôleur ne duplique pas les listeners de drop sur les jours', async () => {
        const firstMount = await mountController(
            'grid',
            GridController,
            gridMarkup()
        )

        const root = firstMount.root
        const firstController = firstMount.controller

        const firstDrop = vi
            .spyOn(firstController, 'dropOnDay')
            .mockImplementation(() => { })

        root.remove()
        await flushDom()

        document.body.append(root)
        await flushDom()

        const day = root.querySelector('.day-col')

        const dropEvent = new Event('drop', {
            bubbles: true,
            cancelable: true
        })

        day.dispatchEvent(dropEvent)

        expect(firstDrop).toHaveBeenCalledTimes(1)
    })
})
