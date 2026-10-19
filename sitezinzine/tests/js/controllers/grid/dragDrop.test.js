import { describe, expect, test, vi } from 'vitest'
import * as dragDrop from '../../../../assets/controllers/grid/dragDrop'
import { element } from '../../support/dom'

function context(dragged) {
    const root = element()
    const day = element({ dayIndex: '6' })
    day.className = 'day-col'
    root.append(day)
    return {
        element: root, dragged, dayTargets: [day], CELL_H: 14, CELL_MIN: 15,
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
        const card = element(); card.style.top = '28px'
        const ctx = context(); const day = ctx.dayTargets[0]
        day.append(card); day.classList.add('drag-over')
        dragDrop.makeDraggable.call(ctx, card, 'grid')
        card.dispatchEvent(new Event('dragstart'))
        expect(ctx.dragged).toBe(card)
        expect(ctx.fromDay).toBe(day)
        expect(ctx.fromStartIndex).toBe(2)
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
        const ctx = context(element(data)); const day = ctx.dayTargets[0]
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
        expect(card.style.top).toBe(`${92 * 14}px`)
        expect(card.style.height).toBe('54px')
        dragDrop.placePostIt.call(ctx, ctx.dayTargets[0], -2)
        expect(card.style.top).toBe('0px')
    })
})
