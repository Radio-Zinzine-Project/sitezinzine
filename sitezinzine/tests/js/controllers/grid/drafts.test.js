import { describe, expect, test, vi } from 'vitest'
import * as drafts from '../../../../assets/controllers/grid/drafts'
import { element, jsonResponse, mockNavigation, flushDom } from '../../support/dom'

function draftContext(data = {}) {
    return {
        dragged: element({ source: 'grid', isManualDraft: 'true', draftId: '42', startsAt: '2026-10-07 14:00:00', ...data }),
        currentMode: 'special', hasTrashZoneTarget: false,
        canDropInTrash: drafts.canDropInTrash,
        getStartsAtFromDrop: () => '2026-10-08 15:00:00',
        saveCurrentMode: vi.fn(), saveScrollTarget: vi.fn(),
    }
}

describe('Déplacements et suppressions des drafts', () => {
    test('la corbeille accepte seulement les drafts manuels de grille en mode ponctuel', () => {
        const ctx = draftContext()
        expect(drafts.canDropInTrash.call(ctx)).toBe(true)
        ctx.currentMode = 'regular'
        expect(drafts.canDropInTrash.call(ctx)).toBe(false)
        ctx.currentMode = 'special'; ctx.dragged.dataset.isManualDraft = 'false'
        expect(drafts.canDropInTrash.call(ctx)).toBe(false)
    })

    test('un déplacement sauvegarde la position et attend la confirmation serveur', async () => {
        const navigation = mockNavigation(); const ctx = draftContext()
        fetch.mockResolvedValue(jsonResponse({ success: true }))
        await drafts.moveManualDraftFromDrop.call(ctx, element(), 60)
        expect(fetch.mock.calls[0][0]).toBe('/admin/grid-drafts/move')
        expect(Object.fromEntries(fetch.mock.calls[0][1].body)).toEqual({ draftId: '42', startsAt: '2026-10-08 15:00:00' })
        expect(ctx.saveScrollTarget).toHaveBeenCalledWith('2026-10-08 15:00:00')
        expect(navigation.reload).toHaveBeenCalledOnce()
    })

    test('un chevauchement refusé conserve le draft et ne navigue pas', async () => {
        const navigation = mockNavigation(); const ctx = draftContext()
        fetch.mockResolvedValue(jsonResponse({ success: false, error: 'Chevauchement' }, false))
        await drafts.moveManualDraftFromDrop.call(ctx, element(), 60)
        expect(window.alert).toHaveBeenCalledWith('Chevauchement')
        expect(navigation.reload).not.toHaveBeenCalled()
        expect(ctx.dragged.dataset.startsAt).toBe('2026-10-07 14:00:00')
    })

    test.each(['single', 'rebroadcasts', 'group'])('la suppression de groupe transmet le choix %s et ferme la modale', async deleteMode => {
        const navigation = mockNavigation(); const ctx = draftContext({ assignmentGroupKey: 'rule_1' })
        fetch.mockResolvedValue(jsonResponse({ success: true }))
        const action = drafts.dropOnTrash.call(ctx, { preventDefault: vi.fn() })
        document.querySelector(`[data-delete-mode="${deleteMode}"]`).click()
        await action
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ draftId: '42', deleteMode })
        expect(document.querySelector('[role="dialog"]')).toBeNull()
        expect(navigation.reload).toHaveBeenCalledOnce()
    })

    test('fermer la modale de suppression par le backdrop ne supprime rien', async () => {
        const ctx = draftContext({ assignmentGroupKey: 'group' })
        const action = drafts.dropOnTrash.call(ctx, { preventDefault: vi.fn() })
        document.querySelector('.rebroadcast-modal-overlay').click()
        await action
        expect(fetch).not.toHaveBeenCalled()
        expect(document.querySelector('[role="dialog"]')).toBeNull()
    })

    test.each([
        [{ specialItemType: 'manual_live', categoryId: '7' }, '/admin/grid-drafts/manual-live', { categoryId: '7', startsAt: '2026-10-08 15:00:00' }],
        [{ specialItemType: 'manual_emission', emissionId: '12', emissionDuration: '45' }, '/admin/grid-drafts/manual', { emissionId: '12', startsAt: '2026-10-08 15:00:00', durationMinutes: '45', draftType: 'manual_special' }],
    ])('le dépôt crée le bon type de ponctuel %j', async (data, url, payload) => {
        const navigation = mockNavigation(); const ctx = draftContext(data)
        fetch.mockResolvedValue(jsonResponse({ success: true, draftId: 99 }))
        await drafts.createSpecialDraftFromDrop.call(ctx, element(), 60)
        expect(fetch.mock.calls[0][0]).toBe(url)
        expect(Object.fromEntries(fetch.mock.calls[0][1].body)).toEqual(payload)
        expect(ctx.saveScrollTarget).toHaveBeenCalledWith(payload.startsAt, 99)
        expect(navigation.reload).toHaveBeenCalledOnce()
    })

    test('la modale de rediffusions valide uniquement les dates complètes', async () => {
        const navigation = mockNavigation()
        const ctx = { selectedPostit: element({ draftId: '42' }) }
        fetch.mockResolvedValueOnce(jsonResponse({ items: [] })).mockResolvedValueOnce(jsonResponse({ success: true }))
        await drafts.openRebroadcastModal.call(ctx)
        document.querySelector('[data-confirm-rebroadcasts]').click()
        expect(window.alert).toHaveBeenCalledWith('Ajoute au moins une rediffusion.')
        expect(fetch).toHaveBeenCalledTimes(1)
        document.querySelector('[data-rebroadcast-date]').value = '2026-10-08'
        document.querySelector('[data-rebroadcast-time]').value = '15:15'
        document.querySelector('[data-add-rebroadcast-row]').click()
        document.querySelector('[data-confirm-rebroadcasts]').click()
        await flushDom()
        expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ draftId: '42', rebroadcasts: ['2026-10-08 15:15:00'] })
        expect(document.querySelector('[role="dialog"]')).toBeNull()
        expect(navigation.reload).toHaveBeenCalledOnce()
    })

    test('supprimer une rediffusion depuis la modale mémorise son emplacement puis recharge la grille', async () => {
        const navigation = mockNavigation()
        window.confirm.mockReturnValue(true)

        const ctx = {
            selectedPostit: element({ draftId: '42' }),
            saveScrollTarget: vi.fn()
        }

        fetch
            .mockResolvedValueOnce(
                jsonResponse({
                    items: [
                        {
                            id: 43,
                            number: 1,
                            startsAt: '2026-10-08 15:00:00'
                        }
                    ]
                })
            )
            .mockResolvedValueOnce(
                jsonResponse({ success: true })
            )

        await drafts.openRebroadcastModal.call(ctx)

        document
            .querySelector('[data-delete-rebroadcast-id]')
            .click()

        await flushDom()

        expect(
            JSON.parse(fetch.mock.calls[1][1].body)
        ).toEqual({
            draftId: '43',
            deleteMode: 'single'
        })

        expect(ctx.saveScrollTarget).toHaveBeenCalledWith(
            '2026-10-08 15:00:00'
        )

        expect(window.alert).not.toHaveBeenCalled()

        expect(navigation.reload).toHaveBeenCalledOnce()
    })
})
