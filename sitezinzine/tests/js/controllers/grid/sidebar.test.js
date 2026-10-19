import { describe, expect, test, vi } from 'vitest'
import * as sidebar from '../../../../assets/controllers/grid/sidebar'
import { element, deferred, jsonResponse } from '../../support/dom'

function selectionContext(readonly = false) {
    return {
        currentMode: 'regular', selectedPostit: null, isReadonly: () => readonly,
        emptyStateTarget: element(), sidebarPanelTarget: element(), slotSummaryTarget: element(),
        arbitrationActionsTarget: element(), slotActionsTarget: element(),
        buildSlotSummary: () => '<p>Résumé</p>', loadLinkedDiffusions: vi.fn().mockResolvedValue(),
        buildConflictArbitrationUI: vi.fn(() => '<button>Résoudre</button>'),
        buildArbitrationActions: vi.fn(() => '<button>Déplacer</button>'),
        setEmissionsListHtml: vi.fn(), clearRegularSearch: vi.fn(),
        loadCandidatesForSelectedPostit: vi.fn().mockResolvedValue(),
    }
}

describe('Sélection et données de la sidebar', () => {
    test('une semaine validée reste consultable sans proposer de mutations', async () => {
        const ctx = selectionContext(true)
        const postit = element({ assignedEmissionTitle: 'Émission', hasConflict: 'true' })
        await sidebar.selectSlot(ctx, { currentTarget: postit })
        expect(postit.classList.contains('is-selected')).toBe(true)
        expect(ctx.slotSummaryTarget.textContent).toBe('Résumé')
        expect(ctx.arbitrationActionsTarget.style.display).toBe('none')
        expect(ctx.slotActionsTarget.style.display).toBe('none')
        expect(ctx.loadCandidatesForSelectedPostit).not.toHaveBeenCalled()
        expect(ctx.buildConflictArbitrationUI).not.toHaveBeenCalled()
    })

    test.each([
        [{ isBlocking: 'false', canRestore: 'true' }, 'annulé ou déplacé'],
        [{ slotLocked: 'true' }, 'verrouillé'],
    ])('ghost/verrouillage empêche l’affectation %j', async (data, message) => {
        const ctx = selectionContext(); const previous = element()
        previous.classList.add('is-selected'); ctx.selectedPostit = previous
        await sidebar.selectSlot(ctx, { currentTarget: element(data) })
        expect(previous.classList.contains('is-selected')).toBe(false)
        expect(ctx.setEmissionsListHtml).toHaveBeenCalledWith(expect.stringContaining(message))
        expect(ctx.loadCandidatesForSelectedPostit).not.toHaveBeenCalled()
    })

    test('une sélection active en conflit propose les arbitrages et charge les candidats', async () => {
        const ctx = selectionContext()
        await sidebar.selectSlot(ctx, { currentTarget: element({ hasConflict: 'true', assignedEmissionTitle: 'A' }) })
        expect(ctx.arbitrationActionsTarget.textContent).toBe('Résoudre')
        expect(ctx.slotActionsTarget.style.display).toBe('block')
        expect(ctx.loadCandidatesForSelectedPostit).toHaveBeenCalledOnce()
    })

    test('le ghost recouvert reste une exception distincte de l’émission programmée', () => {
        const postit = element({ hasCoveredGhost: 'true', assignedEmissionTitle: 'Émission actuelle', categoryTitle: 'Catégorie' })
        postit.dataset.coveredGhost = JSON.stringify({ title: '<b>Ancienne</b>', isCancelled: true })
        const ctx = { getStatusLabel: sidebar.getStatusLabel, buildProjectionSummary: () => '', buildConflictSummary: () => '' }
        const root = element(); root.innerHTML = sidebar.buildSlotSummary(ctx, postit)
        expect(root.querySelector('.slot-summary__exception').textContent).toContain('annulée')
        expect(root.querySelector('.slot-summary__exception b')).toBeNull()
        expect(root.querySelector('.slot-summary__section').textContent).toContain('Émission actuelle')
    })

    test('un ancien chargement de groupe ne remplace pas une nouvelle sélection', async () => {
        const request = deferred(); fetch.mockReturnValue(request.promise)
        const postit = element({ draftId: '12' }); const root = element()
        root.innerHTML = '<div data-linked-diffusions-for="12">Chargement</div>'
        const ctx = { selectedPostit: postit, currentMode: 'regular', slotSummaryTarget: root }
        const loading = sidebar.loadLinkedDiffusions.call(ctx)
        ctx.selectedPostit = element({ draftId: '13' })
        request.resolve(jsonResponse({ success: true, assignmentGroupKey: 'ancien', items: [{ label: 'Ancienne émission' }] }))
        await loading
        expect(ctx.selectedPostit.dataset.assignmentGroupKey).toBeUndefined()
        expect(root.textContent).toBe('Chargement')
    })

    test('un groupe chargé rattache la clé et échappe les libellés', async () => {
        fetch.mockResolvedValue(jsonResponse({ success: true, assignmentGroupKey: 'rule_12', items: [{ label: '<img src=x>', startsAt: '2026-10-06 14:00:00' }] }))
        const postit = element({ draftId: '12' }); const root = element()
        root.innerHTML = '<div data-linked-diffusions-for="12"></div>'
        await sidebar.loadLinkedDiffusions.call({ selectedPostit: postit, currentMode: 'regular', slotSummaryTarget: root })
        expect(postit.dataset.assignmentGroupKey).toBe('rule_12')
        expect(root.querySelector('img')).toBeNull()
        expect(root.textContent).toContain('<img src=x>')
    })

    test('une ancienne sélection ne reprend pas la main après le chargement asynchrone des diffusions liées', async () => {
        const ctx = selectionContext()

        const firstRequest = deferred()
        const secondRequest = deferred()

        ctx.loadLinkedDiffusions = vi
            .fn()
            .mockImplementationOnce(() => firstRequest.promise)
            .mockImplementationOnce(() => secondRequest.promise)

        const firstPostit = element({
            assignedEmissionTitle: 'Émission A'
        })

        const secondPostit = element({
            assignedEmissionTitle: 'Émission B'
        })

        const firstSelection = sidebar.selectSlot(ctx, {
            currentTarget: firstPostit
        })

        const secondSelection = sidebar.selectSlot(ctx, {
            currentTarget: secondPostit
        })

        expect(ctx.selectedPostit).toBe(secondPostit)

        secondRequest.resolve()
        await secondSelection

        expect(
            ctx.loadCandidatesForSelectedPostit
        ).toHaveBeenCalledTimes(1)

        ctx.loadCandidatesForSelectedPostit.mockClear()
        ctx.clearRegularSearch.mockClear()

        firstRequest.resolve()
        await firstSelection

        expect(ctx.selectedPostit).toBe(secondPostit)

        expect(
            ctx.clearRegularSearch
        ).not.toHaveBeenCalled()

        expect(
            ctx.loadCandidatesForSelectedPostit
        ).not.toHaveBeenCalled()
    })

    test('un créneau manuel ouvre la sidebar ponctuelle sans charger les candidats réguliers', async () => {
        const ctx = selectionContext()

        ctx.specialEmptyStateTarget = element()
        ctx.specialSidebarPanelTarget = element()
        ctx.specialSlotSummaryTarget = element()
        ctx.buildSpecialSlotSummary = vi.fn(
            () => '<p>Résumé ponctuel</p>'
        )

        const postit = element({
            isManualDraft: 'true',
            assignedEmissionTitle: 'Émission ponctuelle',
            draftType: 'manual_special'
        })

        await sidebar.selectSlot(ctx, {
            currentTarget: postit
        })

        expect(ctx.selectedPostit).toBe(postit)
        expect(postit.classList.contains('is-selected')).toBe(true)

        expect(
            ctx.specialEmptyStateTarget.style.display
        ).toBe('none')

        expect(
            ctx.specialSidebarPanelTarget.style.display
        ).toBe('block')

        expect(
            ctx.buildSpecialSlotSummary
        ).toHaveBeenCalledWith(postit)

        expect(
            ctx.specialSlotSummaryTarget.textContent
        ).toBe('Résumé ponctuel')

        expect(
            ctx.loadLinkedDiffusions
        ).not.toHaveBeenCalled()

        expect(
            ctx.loadCandidatesForSelectedPostit
        ).not.toHaveBeenCalled()
    })

    test.each([
        [
            {
                isManualDraft: 'true',
                draftType: 'manual_special',
                assignedEmissionTitle: 'Émission spéciale'
            },
            'Non régulière',
            true
        ],
        [
            {
                isManualDraft: 'true',
                draftType: 'manual_live',
                emissionIsAutoGenerated: 'true',
                assignedEmissionTitle: 'Direct'
            },
            'Direct non régulier',
            true
        ],
        [
            {
                isManualDraft: 'true',
                draftType: 'manual_rebroadcast',
                nombreDiffusion: '3',
                assignedEmissionTitle: 'Rediffusion'
            },
            'Rediffusion non régulière 2',
            false
        ],
    ])(
        'le résumé ponctuel distingue correctement les types de programmation %#',
        (data, expectedType, canAddRebroadcasts) => {
            const postit = element({
                startsAt: '2026-10-06 14:00:00',
                endsAt: '2026-10-06 15:00:00',
                categoryTitle: 'Catégorie test',
                draftId: '42',
                ...data
            })

            const ctx = {
                escapeHtml: sidebar.escapeHtml
            }

            const root = element()

            root.innerHTML = sidebar.buildSpecialSlotSummary(
                {
                    escapeHtml: value => String(value)
                        .replaceAll('&', '&amp;')
                        .replaceAll('<', '&lt;')
                        .replaceAll('>', '&gt;')
                        .replaceAll('"', '&quot;')
                        .replaceAll("'", '&#039;')
                },
                postit
            )

            expect(root.textContent).toContain(expectedType)

            expect(
                root.querySelector(
                    '[data-action="click->grid#openRebroadcastModal"]'
                ) !== null
            ).toBe(canAddRebroadcasts)

            expect(
                root.querySelector('[data-linked-diffusions-for="42"]')
            ).not.toBeNull()
        }
    )
})
