import { describe, expect, test } from 'vitest'
import * as conflicts from '../../../../assets/controllers/grid/conflicts'
import { element } from '../../support/dom'

const context = {
    ...conflicts,
    buildConflictGroup: postit => conflicts.buildConflictGroup(context, postit),
}

describe('Conflits affichés et arbitrages proposés', () => {
    test('les conflits invalides ne rendent pas la sidebar inutilisable', () => {
        for (const raw of ['malformed', '{}', 'null']) {
            expect(conflicts.parseConflictWith(element({ conflictWith: raw }))).toEqual([])
        }
    })

    test('regroupe une occurrence et ses conflits sans dupliquer la sélection', () => {
        const postit = element({ slotId: '12', startsAt: 'nouvelle date', originalStartsAt: 'origine' })
        postit.dataset.conflictWith = JSON.stringify([
            { slotId: '12', startsAt: 'origine' },
            { slotId: '13', startsAt: 'origine', segmentKey: 'other' },
            { slotId: '13', startsAt: 'origine', segmentKey: 'other' },
        ])
        const group = conflicts.buildConflictGroup(context, postit)
        expect(group).toHaveLength(2)
        expect(group[0]).toMatchObject({ startsAt: 'origine', isSelectedOccurrence: true })
        expect(group[1].slotId).toBe('13')
    })

    test.each([
        [{ broadcastRank: 1 }, '1re diffusion'],
        [{ broadcastRank: 3 }, 'Rediffusion 2'],
        [{ broadcastRank: 3, isProjectedOverride: true }, 'Occurrence déplacée'],
    ])('identifie le passage %j', (item, label) => {
        expect(conflicts.buildOccurrenceTypeLabel(item)).toBe(label)
    })

    test('le résumé échappe les titres et indique le rang de rediffusion', () => {
        const postit = element({ hasConflict: 'true', conflictSeverity: 'contained', conflictType: 'same_rule_overlap', conflictCount: '1' })
        postit.dataset.conflictWith = JSON.stringify([{ categoryTitle: '<img src=x>', ruleDisplayName: 'A & B', broadcastRank: 2 }])
        const root = element()
        root.innerHTML = conflicts.buildConflictSummary(context, postit)
        expect(root.querySelector('img')).toBeNull()
        expect(root.textContent).toContain('<img src=x>')
        expect(root.textContent).toContain('Conflit inclus')
        expect(root.textContent).toContain('Rediffusion 1')
        expect(conflicts.buildConflictSummary(context, element())).toBe('')
    })

    test('une occurrence déplacée propose un retour à l’origine et indexe les autres conflits', () => {
        const postit = element({ hasConflict: 'true', isProjectedOverride: 'true', slotId: '12', originalStartsAt: 'origine' })
        postit.dataset.conflictWith = JSON.stringify([{ slotId: '13', startsAt: 'autre' }])
        const root = element()
        root.innerHTML = conflicts.buildConflictArbitrationUI(context, postit)
        const selected = root.querySelector('.conflict-card--selected')
        expect(selected.querySelector('[data-action="click->grid#clearReschedule"]')).not.toBeNull()
        expect(selected.querySelector('[data-action="click->grid#rescheduleNextWeek"]')).toBeNull()
        expect(root.querySelector('[data-action="click->grid#arbitrateCancel"]').dataset.conflictIndex).toBe('0')
    })

    test('une occurrence sélectionnée normale propose toutes les actions de résolution', () => {
        const postit = element({
            hasConflict: 'true',
            slotId: '12',
            startsAt: '2026-10-07 17:00:00',
            categoryTitle: 'Émission sélectionnée',
            broadcastRank: '1'
        })

        postit.dataset.conflictWith = JSON.stringify([
            {
                slotId: '13',
                startsAt: '2026-10-07 17:30:00',
                categoryTitle: 'Émission en conflit',
                broadcastRank: 2
            }
        ])

        const root = element()

        root.innerHTML = conflicts.buildConflictArbitrationUI(
            context,
            postit
        )

        const selected = root.querySelector(
            '.conflict-card--selected'
        )

        expect(selected).not.toBeNull()

        expect(
            selected.querySelector(
                '[data-action="click->grid#reschedulePreviousWeek"]'
            )
        ).not.toBeNull()

        expect(
            selected.querySelector(
                '[data-action="click->grid#rescheduleNextWeek"]'
            )
        ).not.toBeNull()

        expect(
            selected.querySelector(
                '[data-action="click->grid#toggleCustomRescheduleForm"]'
            )
        ).not.toBeNull()

        expect(
            selected.querySelector(
                '[data-action="click->grid#submitCustomReschedule"]'
            )
        ).not.toBeNull()

        expect(
            selected.querySelector(
                '[data-action="click->grid#cancelOccurrence"]'
            )
        ).not.toBeNull()

        expect(
            selected.querySelector(
                '[data-action="click->grid#clearReschedule"]'
            )
        ).toBeNull()

        const other = root.querySelector(
            '.conflict-card:not(.conflict-card--selected)'
        )

        expect(other).not.toBeNull()

        expect(
            other.querySelector(
                '[data-action="click->grid#arbitratePreviousWeek"]'
            )
        ).not.toBeNull()

        expect(
            other.querySelector(
                '[data-action="click->grid#arbitrateNextWeek"]'
            )
        ).not.toBeNull()

        expect(
            other.querySelector(
                '[data-action="click->grid#arbitrateCancel"]'
            )
        ).not.toBeNull()
    })

    test('ne duplique pas la même occurrence si seul le segment sélectionné possède un segmentKey', () => {
        const postit = element({
            segmentKey: 'segment-12',
            slotId: '12',
            startsAt: '2026-10-07 17:00:00',
            categoryTitle: 'Émission sélectionnée'
        })

        postit.dataset.conflictWith = JSON.stringify([
            {
                slotId: '12',
                startsAt: '2026-10-07 17:00:00',
                categoryTitle: 'Même émission'
            },
            {
                segmentKey: 'segment-13',
                slotId: '13',
                startsAt: '2026-10-07 17:30:00',
                categoryTitle: 'Autre émission'
            }
        ])

        const group = conflicts.buildConflictGroup(
            context,
            postit
        )

        expect(group).toHaveLength(2)

        expect(group[0]).toMatchObject({
            segmentKey: 'segment-12',
            slotId: '12',
            startsAt: '2026-10-07 17:00:00',
            isSelectedOccurrence: true
        })

        expect(group[1]).toMatchObject({
            segmentKey: 'segment-13',
            slotId: '13'
        })
    })

    test('conserve deux occurrences du même slot lorsqu’elles ont des horaires différents', () => {
        const postit = element({
            segmentKey: 'segment-12-origin',
            slotId: '12',
            startsAt: '2026-10-07 17:00:00',
            categoryTitle: 'Occurrence sélectionnée'
        })

        postit.dataset.conflictWith = JSON.stringify([
            {
                slotId: '12',
                startsAt: '2026-10-14 17:00:00',
                categoryTitle: 'Autre occurrence du même slot'
            }
        ])

        const group = conflicts.buildConflictGroup(
            context,
            postit
        )

        expect(group).toHaveLength(2)

        expect(group[0]).toMatchObject({
            slotId: '12',
            startsAt: '2026-10-07 17:00:00',
            isSelectedOccurrence: true
        })

        expect(group[1]).toMatchObject({
            slotId: '12',
            startsAt: '2026-10-14 17:00:00'
        })
    })
})
