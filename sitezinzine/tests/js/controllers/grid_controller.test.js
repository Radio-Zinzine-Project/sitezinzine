import { describe, expect, test, vi } from 'vitest'
import GridController from '../../../assets/controllers/grid_controller'
import { mountController, flushDom, jsonResponse } from '../support/dom'

function gridMarkup(readonly = false) {
    return `<div data-controller="grid" data-grid-readonly-value="${readonly}" data-grid-week-start-value="2026-10-06">
        <button data-grid-target="modeRegularBtn" data-action="click->grid#showRegularMode"></button>
        <button data-grid-target="modeSpecialBtn" data-action="click->grid#showSpecialMode"></button>
        <div data-grid-target="regularPanel"><input data-grid-target="regularSearch" data-action="input->grid#regularSearch"><div data-grid-target="emissionsList"></div></div>
        <div data-grid-target="specialPanel">
        <select data-grid-target="specialCategorySelect"
        data-action="change->grid#specialCategoryChanged">
        <option value="">Sélectionne une catégorie</option>
        <option value="42" selected>Catégorie A</option>
        <option value="43">Catégorie B</option>
    </select>
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
        fetch.mockResolvedValue(
            jsonResponse({
                items: [],
                total: 0,
                hasMore: false
            })
        )

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

    test('recharge les émissions de la catégorie non régulière déjà sélectionnée après reconnexion', async () => {
        sessionStorage.setItem('gridCurrentMode', 'special')

        fetch.mockResolvedValue(
            jsonResponse({
                items: [
                    {
                        id: 42,
                        title: 'Émission test',
                        durationMinutes: 60,
                        meta: 'Catégorie A',
                        playLabel: '1 diffusion'
                    }
                ],
                total: 1,
                hasMore: false
            })
        )

        const { controller, root } = await mountController(
            'grid',
            GridController,
            gridMarkup()
        )

        expect(controller.currentMode).toBe('special')
        expect(controller.specialCategorySelectTarget.value).toBe('42')

        await vi.waitFor(() => {
            const requests = fetch.mock.calls.filter(([url]) =>
                String(url).includes('/admin/grille/special-candidates')
            )

            expect(requests).toHaveLength(1)

            const url = new URL(requests[0][0], 'http://localhost')
            expect(url.searchParams.get('categoryId')).toBe('42')

            const card = root.querySelector(
                '[data-special-item-type="manual_emission"]'
            )

            expect(card).not.toBeNull()
            expect(card.dataset.emissionId).toBe('42')
            expect(card.draggable).toBe(true)
        })
    })

    test('mémorise la catégorie non régulière lors de sa sélection', async () => {
        const { controller } = await mountController(
            'grid',
            GridController,
            gridMarkup()
        )

        // Évite une requête réseau : on teste uniquement
        // la mémorisation de la catégorie.
        vi.spyOn(controller, 'loadSpecialCandidates')
            .mockResolvedValue()

        controller.specialCategorySelectTarget.value = '43'

        await controller.specialCategoryChanged()

        expect(
            sessionStorage.getItem('gridSpecialCategoryId')
        ).toBe('43')
    })

    test('restaure la catégorie non régulière mémorisée après rechargement', async () => {
        sessionStorage.setItem('gridCurrentMode', 'special')
        sessionStorage.setItem('gridSpecialCategoryId', '43')

        fetch.mockResolvedValue(
            jsonResponse({
                items: [{
                    id: 99,
                    title: 'Émission restaurée',
                    durationMinutes: 60,
                    meta: 'Catégorie B',
                    playLabel: '0 diffusion'
                }],
                total: 1,
                hasMore: false
            })
        )

        const markup = gridMarkup().replace(
            '<option value="42" selected>',
            '<option value="42">'
        )

        const { controller, root } = await mountController(
            'grid',
            GridController,
            markup
        )

        await vi.waitFor(() => {
            expect(controller.specialCategorySelectTarget.value).toBe('43')

            const requests = fetch.mock.calls.filter(([url]) =>
                String(url).includes('/admin/grille/special-candidates')
            )

            expect(requests).toHaveLength(1)

            const url = new URL(requests[0][0], 'http://localhost')
            expect(url.searchParams.get('categoryId')).toBe('43')

            const card = root.querySelector(
                '[data-special-item-type="manual_emission"]'
            )

            expect(card).not.toBeNull()
            expect(card.dataset.emissionId).toBe('99')
            expect(card.draggable).toBe(true)
        })
    })

})
