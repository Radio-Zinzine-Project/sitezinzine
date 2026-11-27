import { describe, expect, test, vi } from 'vitest'
import * as dragDrop from '../../../../assets/controllers/grid/dragDrop'
import { createTimeGeometry } from '../../../../assets/controllers/grid/timeGeometry'
import { createSpecialDraftFromDrop, moveManualDraftFromDrop } from '../../../../assets/controllers/grid/drafts'
import { placePendingRebroadcastFromDrop } from '../../../../assets/controllers/grid/pendingRebroadcasts'
import { element, jsonResponse, mockNavigation } from '../../support/dom'

function context(dragged) {
    const root = element()
    const day = element({ dayIndex: '6' })
    day.className = 'day-col'
    root.append(day)
    return {
        element: root, dragged, dayTargets: [day], CELL_H: 14, CELL_MIN: 15,
        daySlots: 96, breakEnd: 26,
        timeGeometry: createTimeGeometry({
            slotMinutes: 15, slotHeight: 14, daySlots: 96,
            breakStart: 4, breakEnd: 26, breakHeight: 28,
        }),
        getWeekStart: () => '2026-12-29', hasTrashZoneTarget: false,
        durationToCells: duration => Math.ceil(duration / 15),
        durationToPx: duration => duration / 15 * 14 - 2,
        placePostIt: vi.fn(), placePendingRebroadcastFromDrop: vi.fn(),
        createSpecialDraftFromDrop: vi.fn(), moveManualDraftFromDrop: vi.fn(),
    }
}

describe('Dépôts dans la grille', () => {
    test('le lundi de la semaine radio peut appartenir à l’année suivante', () => {
        const ctx = context()
        expect(dragDrop.getStartsAtFromDrop.call(ctx, ctx.dayTargets[0], 95)).toBe('2027-01-04 23:45:00')
    })

    test.each(['slotLocked', 'canRestore', 'isBlocking'])('un slot %s ne devient pas déplaçable', flag => {
        const card = element({ [flag]: flag === 'isBlocking' ? 'false' : 'true' })
        const ctx = context()
        dragDrop.makeDraggable.call(ctx, card, 'grid')
        card.dispatchEvent(new Event('dragstart'))
        expect(card.draggable).toBe(false)
        expect(ctx.dragged).toBeUndefined()
    })

    test('dragstart mémorise la source et dragend nettoie les surbrillances', () => {
        const card = element({ startIndex: '26' }); card.style.top = '84px'
        const ctx = context(); const day = ctx.dayTargets[0]
        day.append(card); day.classList.add('drag-over')
        dragDrop.makeDraggable.call(ctx, card, 'grid')
        card.dispatchEvent(new Event('dragstart'))
        expect(ctx.dragged).toBe(card)
        expect(ctx.fromDay).toBe(day)
        expect(ctx.fromStartIndex).toBe(26)
        card.dispatchEvent(new Event('dragend'))
        expect(ctx.dragged).toBeNull()
        expect(day.classList.contains('drag-over')).toBe(false)
    })

    test.each([
        [{ source: 'pending-rebroadcast' }, 'placePendingRebroadcastFromDrop'],
        [{ source: 'pool', specialItemType: 'manual_live' }, 'createSpecialDraftFromDrop'],
        [{ source: 'grid', isManualDraft: 'true' }, 'moveManualDraftFromDrop'],
        [{ source: 'pool' }, 'placePostIt'],
    ])('délègue le dépôt selon son origine %j', (data, handler) => {
        const ctx = context(element({ duration: '15', emissionDuration: '15', ...data })); const day = ctx.dayTargets[0]
        vi.spyOn(day, 'getBoundingClientRect').mockReturnValue({ top: 100 })
        const event = new MouseEvent('drop', { clientY: 142, cancelable: true })
        dragDrop.dropOnDay.call(ctx, event, day)
        expect(ctx[handler]).toHaveBeenCalledWith(day, 3)
        for (const name of ['placePostIt', 'placePendingRebroadcastFromDrop', 'createSpecialDraftFromDrop', 'moveManualDraftFromDrop']) {
            if (name !== handler) expect(ctx[name]).not.toHaveBeenCalled()
        }
    })

    test('un élément verrouillé ne déclenche aucun placement', () => {
        const ctx = context(element({ slotLocked: 'true', source: 'pending-rebroadcast' }))
        dragDrop.dropOnDay.call(ctx, new MouseEvent('drop'), ctx.dayTargets[0])
        expect(ctx.placePendingRebroadcastFromDrop).not.toHaveBeenCalled()
    })

    test('le placement visuel reste dans la journée et conserve la durée', () => {
        const card = element({ source: 'pool', duration: '60' }); const ctx = context(card)
        dragDrop.placePostIt.call(ctx, ctx.dayTargets[0], 95)
        expect(card.parentElement).toBe(ctx.dayTargets[0])
        expect(card.dataset.source).toBe('grid')
        expect(card.dataset.startIndex).toBe('92')
        expect(card.style.getPropertyValue('--time-index')).toBe('92')
        expect(card.style.getPropertyValue('--after-break')).toBe('1')
        expect(card.style.top).toBe('')
        expect(card.style.height).toBe('54px')
        dragDrop.placePostIt.call(ctx, ctx.dayTargets[0], -2)
        expect(card.dataset.startIndex).toBe('92')
    })

    test.each([
        [0, 15, 0], [42, 15, 3], [55.99, 15, 3],
        [56, 15, null], [70, 15, null], [83.99, 15, null],
        [84, 16, 26], [392, 15, 48], [1050, 75, 95],
        [1050, 76, null], [1064, 15, null], [-1, 15, null],
        [42, 16, null], [42, 60, null], [42, 0, null],
    ])('convertit ou refuse le dépôt à %s px avec %s minutes', (y, duration, index) => {
        const card = element({ source: 'pending-rebroadcast', duration: String(duration), pendingRebroadcastId: '42' })
        const ctx = context(card)
        const day = ctx.dayTargets[0]
        day.classList.add('drag-over')
        vi.spyOn(day, 'getBoundingClientRect').mockReturnValue({ top: 100 })
        const event = { clientY: 100 + y, preventDefault: vi.fn() }
        dragDrop.dropOnDay.call(ctx, event, day)
        expect(day.classList.contains('drag-over')).toBe(false)
        expect(card.dataset.pendingRebroadcastId).toBe('42')
        if (index === null) {
            expect(ctx.placePendingRebroadcastFromDrop).not.toHaveBeenCalled()
            expect(fetch).not.toHaveBeenCalled()
            expect(card.parentNode).toBeNull()
        } else {
            expect(ctx.placePendingRebroadcastFromDrop).toHaveBeenCalledWith(day, index)
        }
    })

    test('place 16 minutes après la bande avec sa hauteur proportionnelle', () => {
        const card = element({ source: 'pool', emissionDuration: '16' })
        const ctx = context(card)
        dragDrop.placePostIt.call(ctx, ctx.dayTargets[0], 26)
        expect(card.dataset.startIndex).toBe('26')
        expect(card.style.getPropertyValue('--time-index')).toBe('26')
        expect(card.style.getPropertyValue('--after-break')).toBe('1')
        expect(parseFloat(card.style.height)).toBeCloseTo(12.9333)
    })

    test.each([
        ['2026-12-29', 0, 15, '2027-01-04 00:00:00'],
        ['2026-12-29', 42, 15, '2027-01-04 00:45:00'],
        ['2026-12-29', 84, 16, '2027-01-04 06:30:00'],
        ['2026-12-29', 392, 45, '2027-01-04 12:00:00'],
        ['2026-12-29', 1050, 75, '2027-01-04 23:45:00'],
        ['2026-03-24', 42, 15, '2026-03-29 00:45:00', '5'],
        ['2026-03-24', 84, 45, '2026-03-29 06:30:00', '5'],
        ['2026-10-20', 42, 15, '2026-10-25 00:45:00', '5'],
        ['2026-10-20', 84, 45, '2026-10-25 06:30:00', '5'],
    ])('envoie la date civile pour la semaine %s à %s px', async (week, y, duration, startsAt, dayIndex = '6') => {
        const navigation = mockNavigation()
        fetch.mockResolvedValue(jsonResponse({ success: true }))
        const ctx = context(element({ source: 'grid', isManualDraft: 'true', duration: String(duration), draftId: '42' }))
        ctx.getWeekStart = () => week
        ctx.getStartsAtFromDrop = dragDrop.getStartsAtFromDrop
        ctx.moveManualDraftFromDrop = moveManualDraftFromDrop
        ctx.saveCurrentMode = vi.fn()
        ctx.saveScrollTarget = vi.fn()
        const day = ctx.dayTargets[0]
        day.dataset.dayIndex = dayIndex
        vi.spyOn(day, 'getBoundingClientRect').mockReturnValue({ top: -140, left: -400 })
        await dragDrop.dropOnDay.call(ctx, { clientY: y - 140, preventDefault: vi.fn() }, day)
        expect(fetch).toHaveBeenCalledOnce()
        expect(fetch.mock.calls[0][0]).toBe('/admin/grid-drafts/move')
        expect(Object.fromEntries(fetch.mock.calls[0][1].body)).toEqual({ draftId: '42', startsAt })
        expect(navigation.reload).toHaveBeenCalledOnce()
    })

    test.each([
        [{ source: 'pool', specialItemType: 'manual_emission', emissionId: '12', emissionDuration: '45' },
            '/admin/grid-drafts/manual', { emissionId: '12', durationMinutes: '45', draftType: 'manual_special' }],
        [{ source: 'pool', specialItemType: 'manual_live', categoryId: '7', emissionDuration: '90' },
            '/admin/grid-drafts/manual-live', { categoryId: '7' }],
        [{ source: 'pending-rebroadcast', pendingRebroadcastId: '42', duration: '45', assignmentGroupKey: 'rule_12' },
            '/admin/pending-rebroadcasts/42/place', {}],
    ])('raccorde la branche réelle %j', async (data, url, fields) => {
        const navigation = mockNavigation()
        fetch.mockResolvedValue(jsonResponse({ success: true }))
        const card = element(data)
        const ctx = context(card)
        ctx.getStartsAtFromDrop = dragDrop.getStartsAtFromDrop
        ctx.createSpecialDraftFromDrop = createSpecialDraftFromDrop
        ctx.placePendingRebroadcastFromDrop = placePendingRebroadcastFromDrop
        ctx.isReadonly = () => false
        ctx.saveScrollTarget = vi.fn()
        const day = ctx.dayTargets[0]
        vi.spyOn(day, 'getBoundingClientRect').mockReturnValue({ top: 0 })
        for (const clientY of [42, 56, 1064]) {
            ctx.dragged = card
            await dragDrop.dropOnDay.call(ctx, { clientY, preventDefault: vi.fn() }, day)
        }
        expect(fetch).not.toHaveBeenCalled()
        expect(day.childElementCount).toBe(0)
        ctx.dragged = card
        await dragDrop.dropOnDay.call(ctx, { clientY: 84, preventDefault: vi.fn() }, day)
        const [calledUrl, request] = fetch.mock.calls[0]
        expect(calledUrl).toBe(url)
        const body = typeof request.body === 'string' ? JSON.parse(request.body) : Object.fromEntries(request.body)
        expect(body).toEqual({ startsAt: '2027-01-04 06:30:00', ...fields })
        expect(card.dataset.assignmentGroupKey).toBe(data.assignmentGroupKey)
        expect(navigation.reload).toHaveBeenCalledOnce()
    })

    test('la surbrillance est retirée à l’entrée dans la bande', () => {
        const ctx = context(element({ source: 'pool' }))
        const day = ctx.dayTargets[0]
        vi.spyOn(day, 'getBoundingClientRect').mockReturnValue({ top: 0 })
        const preventDefault = vi.fn()
        dragDrop.dragOverDay.call(ctx, { clientY: 42, preventDefault }, day)
        expect(day.classList.contains('drag-over')).toBe(true)
        dragDrop.dragOverDay.call(ctx, { clientY: 56, preventDefault }, day)
        expect(day.classList.contains('drag-over')).toBe(false)
        expect(preventDefault).toHaveBeenCalledOnce()
    })
})
