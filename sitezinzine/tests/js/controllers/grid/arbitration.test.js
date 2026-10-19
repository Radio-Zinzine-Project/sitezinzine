import { describe, expect, test, vi } from 'vitest'
import * as arbitration from '../../../../assets/controllers/grid/arbitration'
import { element, jsonResponse, mockNavigation } from '../../support/dom'

function context() {
    return {
        selectedPostit: element({ slotId: '12', startsAt: 'destination', originalStartsAt: '2026-10-06 14:00:00' }),
        askRebroadcastStrategy: vi.fn().mockResolvedValue({ strategy: 'custom', targets: [{ slotId: 13, newDate: '2026-10-08', newTime: '16:00' }] }),
        askRestoreRebroadcastStrategy: vi.fn().mockResolvedValue({ restoreLinkedRebroadcasts: true }),
        saveScrollTarget: vi.fn(), arbitrationActionsTarget: element(),
    }
}

describe('Arbitrages et restauration d’occurrences', () => {
    test('un déplacement utilise l’origine et transmet la stratégie des rediffusions', async () => {
        const navigation = mockNavigation()
        const ctx = context()

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'move',
            targets: []
        })

        fetch.mockResolvedValue(
            jsonResponse({
                success: true,
                targetWeekStart: '2026-10-13'
            })
        )

        await arbitration.submitWeekReschedule.call(
            ctx,
            'next'
        )

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('week')

        expect(
            Object.fromEntries(fetch.mock.calls[0][1].body)
        ).toEqual({
            slotId: '12',
            startsAt: '2026-10-06 14:00:00',
            direction: 'next',
            rebroadcastStrategy: 'move',
            rebroadcastTargets: JSON.stringify([])
        })

        expect(navigation.href).toBe(
            '/admin/grille/2026-10-13'
        )
    })

    test('une réponse sans semaine cible ne lance pas de navigation', async () => {
        const navigation = mockNavigation(); const initialHref = navigation.href
        fetch.mockResolvedValue(jsonResponse({ success: true }))
        await arbitration.submitWeekReschedule.call(context(), 'next')
        expect(window.alert).toHaveBeenCalledWith(expect.stringContaining('Semaine cible manquante'))
        expect(navigation.href).toBe(initialHref)
    })

    test('un déplacement personnalisé incomplet est arrêté avant la requête', async () => {
        await arbitration.submitCustomReschedule.call(context())
        expect(fetch).not.toHaveBeenCalled()
        expect(window.alert).toHaveBeenCalledWith('Merci de renseigner une date et une heure.')
    })

    test('une date personnalisée complète est transmise avec l’identité d’origine', async () => {
        const navigation = mockNavigation()
        const ctx = context()

        ctx.arbitrationActionsTarget.innerHTML = `
        <input
            type="date"
            data-grid-custom-date
            value="2026-10-08"
        >
        <input
            type="time"
            data-grid-custom-time
            value="16:00"
        >
    `

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'custom',
            targets: [
                {
                    slotId: 13,
                    newDate: '2026-10-08',
                    newTime: '16:00'
                }
            ]
        })

        fetch.mockResolvedValue(
            jsonResponse({
                success: true,
                targetWeekStart: '2026-10-06'
            })
        )

        await arbitration.submitCustomReschedule.call(ctx)

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('custom')

        expect(fetch.mock.calls[0][0]).toBe(
            '/admin/grille/reschedule-custom'
        )

        expect(
            Object.fromEntries(fetch.mock.calls[0][1].body)
        ).toEqual({
            slotId: '12',
            startsAt: '2026-10-06 14:00:00',
            newDate: '2026-10-08',
            newTime: '16:00',
            rebroadcastStrategy: 'custom',
            rebroadcastTargets: JSON.stringify([
                {
                    slotId: 13,
                    newDate: '2026-10-08',
                    newTime: '16:00'
                }
            ])
        })

        expect(navigation.href).toBe(
            '/admin/grille/2026-10-06'
        )
    })

    test('une annulation refusée par l’utilisateur ne fait pas de requête', async () => {
        await arbitration.cancelOccurrence.call(context())
        expect(fetch).not.toHaveBeenCalled()
    })

    test('une annulation confirmée garde la position pour le rechargement', async () => {
        const navigation = mockNavigation(); const ctx = context(); window.confirm.mockReturnValue(true)
        fetch.mockResolvedValue(jsonResponse({ success: true }))
        await arbitration.cancelOccurrence.call(ctx)
        expect(fetch.mock.calls[0][0]).toBe('/admin/grille/cancel-occurrence')
        expect(ctx.saveScrollTarget).toHaveBeenCalledWith('2026-10-06 14:00:00')
        expect(navigation.reload).toHaveBeenCalledOnce()
    })

    test.each([['restoreOccurrence', '/admin/grille/restore-occurrence'], ['clearReschedule', '/admin/grille/clear-reschedule']])('restaurer avec %s transmet le choix des rediffusions liées', async (method, url) => {
        const navigation = mockNavigation(); const ctx = context()
        fetch.mockResolvedValue(jsonResponse({ success: true, targetWeekStart: '2026-10-06' }))
        await arbitration[method].call(ctx)
        expect(fetch.mock.calls[0][0]).toBe(url)
        expect(Object.fromEntries(fetch.mock.calls[0][1].body)).toEqual({ slotId: '12', originalStartsAt: '2026-10-06 14:00:00', restoreLinkedRebroadcasts: '1' })
        expect(navigation.href).toBe('/admin/grille/2026-10-06')
    })

    test('le déplacement d’un conflit utilise l’origine et transmet la stratégie des rediffusions', async () => {
        const navigation = mockNavigation()
        const ctx = context()

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'move',
            targets: []
        })

        fetch.mockResolvedValue(
            jsonResponse({
                success: true,
                targetWeekStart: '2026-10-13'
            })
        )

        const item = {
            slotId: '42',
            startsAt: '2026-10-14 17:00:00',
            originalStartsAt: '2026-10-07 17:00:00'
        }

        await arbitration.callConflictReschedule.call(
            ctx,
            item,
            'next'
        )

        expect(fetch.mock.calls[0][0]).toBe(
            '/admin/grille/reschedule-week'
        )

        expect(
            Object.fromEntries(fetch.mock.calls[0][1].body)
        ).toEqual({
            slotId: '42',
            startsAt: '2026-10-07 17:00:00',
            direction: 'next',
            rebroadcastStrategy: 'move',
            rebroadcastTargets: JSON.stringify([])
        })

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('week')

        expect(navigation.href).toBe(
            '/admin/grille/2026-10-13'
        )
    })

    test('l’annulation d’un conflit utilise l’origine et transmet la stratégie des rediffusions', async () => {
        const navigation = mockNavigation()
        const ctx = context()

        window.confirm.mockReturnValue(true)

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'cancel',
            targets: []
        })

        fetch.mockResolvedValue(
            jsonResponse({
                success: true
            })
        )

        const item = {
            slotId: '42',
            startsAt: '2026-10-14 17:00:00',
            originalStartsAt: '2026-10-07 17:00:00'
        }

        await arbitration.callConflictCancel.call(
            ctx,
            item
        )

        expect(window.confirm).toHaveBeenCalledWith(
            'Annuler cette occurrence en conflit ?'
        )

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('cancel')

        expect(fetch.mock.calls[0][0]).toBe(
            '/admin/grille/cancel-occurrence'
        )

        expect(
            Object.fromEntries(fetch.mock.calls[0][1].body)
        ).toEqual({
            slotId: '42',
            startsAt: '2026-10-07 17:00:00',
            rebroadcastStrategy: 'cancel',
            rebroadcastTargets: JSON.stringify([])
        })

        expect(
            ctx.saveScrollTarget
        ).toHaveBeenCalledWith(
            '2026-10-07 17:00:00'
        )

        expect(
            navigation.reload
        ).toHaveBeenCalledOnce()
    })

    test('refuser l’annulation ne demande pas quoi faire des rediffusions', async () => {
        const ctx = context()

        window.confirm.mockReturnValue(false)

        await arbitration.cancelOccurrence.call(ctx)

        expect(window.confirm).toHaveBeenCalledWith(
            'Annuler cette occurrence de la grille ?'
        )

        expect(
            ctx.askRebroadcastStrategy
        ).not.toHaveBeenCalled()

        expect(fetch).not.toHaveBeenCalled()
    })

    test('refuser l’annulation d’un conflit ne demande pas quoi faire des rediffusions', async () => {
        const ctx = context()

        window.confirm.mockReturnValue(false)

        const item = {
            slotId: '42',
            startsAt: '2026-10-14 17:00:00',
            originalStartsAt: '2026-10-07 17:00:00'
        }

        await arbitration.callConflictCancel.call(
            ctx,
            item
        )

        expect(window.confirm).toHaveBeenCalledWith(
            'Annuler cette occurrence en conflit ?'
        )

        expect(
            ctx.askRebroadcastStrategy
        ).not.toHaveBeenCalled()

        expect(fetch).not.toHaveBeenCalled()

        expect(
            ctx.saveScrollTarget
        ).not.toHaveBeenCalled()
    })

    test('annuler une occurrence ouvre les choix adaptés à une annulation', async () => {
        const ctx = context()

        window.confirm.mockReturnValue(true)

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'keep',
            targets: []
        })

        fetch.mockResolvedValue(
            jsonResponse({})
        )

        await arbitration.cancelOccurrence.call(ctx)

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('cancel')
    })

    test('Retour dans les choix de rediffusion abandonne l’annulation', async () => {
        const ctx = context()

        window.confirm.mockReturnValue(true)

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'dismiss',
            targets: []
        })

        await arbitration.cancelOccurrence.call(ctx)

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('cancel')

        expect(fetch).not.toHaveBeenCalled()

        expect(
            ctx.saveScrollTarget
        ).not.toHaveBeenCalled()
    })

    test('un déplacement d’une semaine ouvre les choix adaptés à ce déplacement', async () => {
        const ctx = context()

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'keep',
            targets: []
        })

        fetch.mockResolvedValue(
            jsonResponse({
                targetWeekStart: '2026-10-13'
            })
        )

        await arbitration.submitWeekReschedule.call(
            ctx,
            'next'
        )

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('week')
    })

    test('Retour dans les choix de rediffusion abandonne le déplacement personnalisé', async () => {
        const ctx = context()

        ctx.arbitrationActionsTarget.innerHTML = `
        <input
            type="date"
            data-grid-custom-date
            value="2026-10-08"
        >
        <input
            type="time"
            data-grid-custom-time
            value="16:00"
        >
    `

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'dismiss',
            targets: []
        })

        await arbitration.submitCustomReschedule.call(ctx)

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('custom')

        expect(fetch).not.toHaveBeenCalled()
    })

    test('un déplacement personnalisé ouvre les choix adaptés à ce déplacement', async () => {
        const ctx = context()

        ctx.arbitrationActionsTarget.innerHTML = `
        <input
            type="date"
            data-grid-custom-date
            value="2026-10-08"
        >
        <input
            type="time"
            data-grid-custom-time
            value="16:00"
        >
    `

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'keep',
            targets: []
        })

        fetch.mockResolvedValue(
            jsonResponse({
                targetWeekStart: '2026-10-13'
            })
        )

        await arbitration.submitCustomReschedule.call(ctx)

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('custom')
    })

    test('Retour dans les choix de rediffusion abandonne le déplacement personnalisé', async () => {
        const ctx = context()

        ctx.arbitrationActionsTarget.innerHTML = `
        <input
            type="date"
            data-grid-custom-date
            value="2026-10-08"
        >
        <input
            type="time"
            data-grid-custom-time
            value="16:00"
        >
    `

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'dismiss',
            targets: []
        })

        await arbitration.submitCustomReschedule.call(ctx)

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('custom')

        expect(fetch).not.toHaveBeenCalled()

        expect(
            ctx.saveScrollTarget
        ).not.toHaveBeenCalled()
    })

    test('un déplacement de conflit ouvre les choix du déplacement d’une semaine', async () => {
        const ctx = context()

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'keep',
            targets: []
        })

        fetch.mockResolvedValue(
            jsonResponse({
                targetWeekStart: '2026-10-13'
            })
        )

        const item = {
            slotId: '42',
            startsAt: '2026-10-14 17:00:00',
            originalStartsAt: '2026-10-07 17:00:00'
        }

        await arbitration.callConflictReschedule.call(
            ctx,
            item,
            'next'
        )

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('week')
    })

    test('Retour dans les choix de rediffusion abandonne le déplacement d’un conflit', async () => {
        const ctx = context()

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'dismiss',
            targets: []
        })

        const item = {
            slotId: '42',
            startsAt: '2026-10-14 17:00:00',
            originalStartsAt: '2026-10-07 17:00:00'
        }

        await arbitration.callConflictReschedule.call(
            ctx,
            item,
            'next'
        )

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('week')

        expect(fetch).not.toHaveBeenCalled()
    })

    test('annuler un conflit ouvre les choix adaptés à une annulation', async () => {
        const ctx = context()

        window.confirm.mockReturnValue(true)

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'keep',
            targets: []
        })

        fetch.mockResolvedValue(
            jsonResponse({})
        )

        const item = {
            slotId: '42',
            startsAt: '2026-10-14 17:00:00',
            originalStartsAt: '2026-10-07 17:00:00'
        }

        await arbitration.callConflictCancel.call(
            ctx,
            item
        )

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('cancel')
    })

    test('Retour dans les choix de rediffusion abandonne l’annulation d’un conflit', async () => {
        const ctx = context()

        window.confirm.mockReturnValue(true)

        ctx.askRebroadcastStrategy.mockResolvedValue({
            strategy: 'dismiss',
            targets: []
        })

        const item = {
            slotId: '42',
            startsAt: '2026-10-14 17:00:00',
            originalStartsAt: '2026-10-07 17:00:00'
        }

        await arbitration.callConflictCancel.call(
            ctx,
            item
        )

        expect(
            ctx.askRebroadcastStrategy
        ).toHaveBeenCalledWith('cancel')

        expect(fetch).not.toHaveBeenCalled()

        expect(
            ctx.saveScrollTarget
        ).not.toHaveBeenCalled()
    })

    test('Retour dans les choix de restauration abandonne la restauration de l’occurrence', async () => {
    const ctx = context()

    ctx.askRestoreRebroadcastStrategy.mockResolvedValue({
        restoreLinkedRebroadcasts: false,
        dismissed: true
    })

    await arbitration.restoreOccurrence.call(ctx)

    expect(
        ctx.askRestoreRebroadcastStrategy
    ).toHaveBeenCalledOnce()

    expect(fetch).not.toHaveBeenCalled()

    expect(
        ctx.saveScrollTarget
    ).not.toHaveBeenCalled()
})

test('Retour dans les choix de restauration abandonne le retour au créneau d’origine', async () => {
    const ctx = context()

    ctx.askRestoreRebroadcastStrategy.mockResolvedValue({
        restoreLinkedRebroadcasts: false,
        dismissed: true
    })

    await arbitration.clearReschedule.call(ctx)

    expect(
        ctx.askRestoreRebroadcastStrategy
    ).toHaveBeenCalledOnce()

    expect(fetch).not.toHaveBeenCalled()

    expect(
        ctx.saveScrollTarget
    ).not.toHaveBeenCalled()
})
})
