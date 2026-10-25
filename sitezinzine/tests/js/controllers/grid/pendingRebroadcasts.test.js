import { describe, expect, test, vi } from 'vitest'
import * as pool from '../../../../assets/controllers/grid/pendingRebroadcasts'
import { escapeHtml } from '../../../../assets/controllers/grid/utils'
import { element, jsonResponse, mockNavigation, deferred } from '../../support/dom'

function poolContext(readonly = false) {
    const ctx = {
        hasPendingRebroadcastListTarget: true, hasPendingRebroadcastStatusTarget: true,
        hasPendingRebroadcastSearchTarget: true, hasPendingRebroadcastCountTarget: true,
        hasPendingRebroadcastPanelTarget: true, hasPendingRebroadcastToggleTarget: true,
        pendingRebroadcastListTarget: element(), pendingRebroadcastStatusTarget: element(),
        pendingRebroadcastSearchTarget: element({}, 'input'), pendingRebroadcastCountTarget: element(),
        pendingRebroadcastPanelTarget: element(), pendingRebroadcastToggleTarget: element({}, 'button'),
        pendingRebroadcastItems: [], pendingRebroadcastLoaded: false,
        isReadonly: () => readonly, escapeHtml, makeDraggable: vi.fn(),
        getStartsAtFromDrop: () => '2026-10-07 15:00:00',
    }
    for (const name of ['loadPendingRebroadcasts', 'reloadPendingRebroadcasts', 'renderPendingRebroadcasts', 'updatePendingRebroadcastCount', 'updatePendingRebroadcastToggle']) {
        ctx[name] = pool[name].bind(ctx)
    }
    return ctx
}

const item = { id: 42, emissionId: 12, title: 'Émission été', category: 'Actualités', durationMinutes: 60, assignmentGroupKey: 'rule_12' }

describe('Émissions à diffuser', () => {
    test('charge les cartes et leur historique sans autoriser du HTML dans les données', async () => {
        const ctx = poolContext()
        fetch.mockResolvedValue(jsonResponse({ success: true, count: 1, items: [{ ...item, title: '<img src=x>', previousDiffusions: [{ date: '2026-10-06 14:00:00', number: 1 }] }] }))
        await pool.loadPendingRebroadcasts.call(ctx)
        expect(ctx.pendingRebroadcastLoaded).toBe(true)
        expect(ctx.pendingRebroadcastCountTarget.textContent).toBe('1')
        const card = ctx.pendingRebroadcastListTarget.querySelector('article')
        expect(card.dataset.assignmentGroupKey).toBe('rule_12')
        expect(card.querySelector('img')).toBeNull()
        expect(card.textContent).toContain('<img src=x>')
        expect(card.querySelector('.pending-rebroadcast-card__tooltip').textContent).toContain('n°1')
        expect(ctx.makeDraggable).toHaveBeenCalledWith(card, 'pending-rebroadcast')
    })

    test('la recherche locale ignore accents/casse et ne réduit pas le compteur total', () => {
        const ctx = poolContext(); ctx.pendingRebroadcastItems = [item, { ...item, id: 43, title: 'Jazz', category: 'Musique' }]
        ctx.updatePendingRebroadcastCount(2)
        ctx.pendingRebroadcastSearchTarget.value = '  ETE '
        pool.renderPendingRebroadcasts.call(ctx)
        expect(ctx.pendingRebroadcastListTarget.querySelectorAll('article')).toHaveLength(1)
        expect(ctx.pendingRebroadcastStatusTarget.textContent).toBe('1 résultat.')
        expect(ctx.pendingRebroadcastCountTarget.textContent).toBe('2')
        ctx.pendingRebroadcastSearchTarget.value = 'musique'
        pool.searchPendingRebroadcasts.call(ctx)
        expect(ctx.pendingRebroadcastListTarget.textContent).toContain('Jazz')
    })

    test('distingue un Parc vide et une recherche sans résultat', () => {
        const ctx = poolContext()
        pool.renderPendingRebroadcasts.call(ctx)
        expect(ctx.pendingRebroadcastStatusTarget.textContent).toContain('Aucune émission')
        ctx.pendingRebroadcastItems = [item]; ctx.pendingRebroadcastSearchTarget.value = 'inconnu'
        pool.renderPendingRebroadcasts.call(ctx)
        expect(ctx.pendingRebroadcastStatusTarget.textContent).toContain('Aucun résultat')
    })

    test('en lecture seule les cartes restent consultables sans glisser-déposer', () => {
        const ctx = poolContext(true); ctx.pendingRebroadcastItems = [item]
        pool.renderPendingRebroadcasts.call(ctx)
        expect(ctx.pendingRebroadcastListTarget.querySelector('article').draggable).toBe(false)
        expect(ctx.makeDraggable).not.toHaveBeenCalled()
    })

    test.each(['http', 'json', 'network'])('un échec %s permet de recharger le Parc', async failure => {
        vi.spyOn(console, 'error').mockImplementation(() => { })
        const ctx = poolContext()
        if (failure === 'http') fetch.mockResolvedValue(jsonResponse({ error: 'Accès refusé' }, false))
        if (failure === 'json') fetch.mockResolvedValue({ ok: true, json: vi.fn().mockRejectedValue(new SyntaxError()) })
        if (failure === 'network') fetch.mockRejectedValue(new Error('Hors ligne'))
        await ctx.loadPendingRebroadcasts()
        expect(ctx.pendingRebroadcastLoaded).toBe(false)
        expect(ctx.pendingRebroadcastListTarget.querySelector('[data-action="click->grid#reloadPendingRebroadcasts"]')).not.toBeNull()
        fetch.mockResolvedValue(jsonResponse({ success: true, items: [item] }))
        await ctx.reloadPendingRebroadcasts()
        expect(ctx.pendingRebroadcastLoaded).toBe(true)
        expect(ctx.pendingRebroadcastListTarget.querySelectorAll('article')).toHaveLength(1)
    })

    test('ouvrir/fermer le panneau charge une fois et maintient aria-expanded', async () => {
        const ctx = poolContext(); ctx.pendingRebroadcastPanelTarget.hidden = true
        fetch.mockResolvedValue(jsonResponse({ success: true, items: [] }))
        await pool.togglePendingRebroadcastPool.call(ctx)
        expect(ctx.pendingRebroadcastToggleTarget.getAttribute('aria-expanded')).toBe('true')
        await pool.togglePendingRebroadcastPool.call(ctx)
        await pool.togglePendingRebroadcastPool.call(ctx)
        expect(fetch).toHaveBeenCalledOnce()
    })

    test('la duplication bloque le bouton puis recharge le Parc', async () => {
        const ctx = poolContext(); const request = deferred()
        const button = element({ pendingRebroadcastId: '42' }, 'button'); button.textContent = 'Dupliquer'
        document.body.append(button)
        fetch.mockReturnValueOnce(request.promise).mockResolvedValueOnce(jsonResponse({ success: true, items: [item, { ...item, id: 43 }] }))
        const action = pool.duplicatePendingRebroadcast.call(ctx, { currentTarget: button, preventDefault: vi.fn(), stopPropagation: vi.fn() })
        expect(button.disabled).toBe(true)
        request.resolve(jsonResponse({ success: true, pendingRebroadcastId: 43 }))
        await action
        expect(fetch.mock.calls[0][0]).toBe('/admin/pending-rebroadcasts/42/duplicate')
        expect(ctx.pendingRebroadcastListTarget.querySelectorAll('article')).toHaveLength(2)
    })

    test('un refus de duplication réactive le bouton et conserve la carte', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => { })
        const ctx = poolContext(); const button = element({ pendingRebroadcastId: '42' }, 'button')
        button.textContent = 'Dupliquer'; document.body.append(button)
        fetch.mockResolvedValue(jsonResponse({ success: false, error: 'Refus métier' }))
        await pool.duplicatePendingRebroadcast.call(ctx, { currentTarget: button, preventDefault: vi.fn(), stopPropagation: vi.fn() })
        expect(button.disabled).toBe(false)
        expect(button.textContent).toBe('Dupliquer')
        expect(window.alert).toHaveBeenCalledWith('Refus métier')
    })

    test('annuler la confirmation de suppression ne fait aucune requête', async () => {
        const ctx = poolContext()
        await pool.deletePendingRebroadcast.call(ctx, { currentTarget: element({ pendingRebroadcastId: '42' }), preventDefault: vi.fn(), stopPropagation: vi.fn() })
        expect(fetch).not.toHaveBeenCalled()
    })

    test('une suppression confirmée consomme uniquement l’ID sélectionné et recharge', async () => {
        window.confirm.mockReturnValue(true)
        const ctx = poolContext()
        fetch.mockResolvedValueOnce(jsonResponse({ success: true })).mockResolvedValueOnce(jsonResponse({ success: true, items: [] }))
        await pool.deletePendingRebroadcast.call(ctx, { currentTarget: element({ pendingRebroadcastId: '42' }), preventDefault: vi.fn(), stopPropagation: vi.fn() })
        expect(fetch.mock.calls[0]).toEqual(['/admin/pending-rebroadcasts/42', expect.objectContaining({ method: 'DELETE' })])
        expect(ctx.pendingRebroadcastStatusTarget.textContent).toContain('Aucune émission')
    })

    test('le dépôt envoie l’horaire au serveur puis recharge sans carte optimiste', async () => {
        const navigation = mockNavigation(); const ctx = poolContext()
        ctx.dragged = element({ source: 'pending-rebroadcast', pendingRebroadcastId: '42' })
        fetch.mockResolvedValue(jsonResponse({ success: true, draftId: 99 }))
        await pool.placePendingRebroadcastFromDrop.call(ctx, element(), 60)
        expect(fetch.mock.calls[0][0]).toBe('/admin/pending-rebroadcasts/42/place')
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ startsAt: '2026-10-07 15:00:00' })
        expect(navigation.reload).toHaveBeenCalledOnce()
        expect(ctx.pendingRebroadcastListTarget.childElementCount).toBe(0)
    })

    test('un dépôt refusé conserve le Pending visible et ne recharge pas', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => { })
        const navigation = mockNavigation(); const ctx = poolContext()
        ctx.dragged = element({ source: 'pending-rebroadcast', pendingRebroadcastId: '42' })
        fetch.mockResolvedValue(jsonResponse({ success: false, error: 'Chevauchement' }, false))
        await pool.placePendingRebroadcastFromDrop.call(ctx, element(), 60)
        expect(window.alert).toHaveBeenCalledWith('Chevauchement')
        expect(navigation.reload).not.toHaveBeenCalled()
        expect(ctx.dragged.dataset.pendingRebroadcastId).toBe('42')
    })

    test('readonly interdit les trois mutations du Parc', async () => {
        const ctx = poolContext(true)
        ctx.dragged = element({ source: 'pending-rebroadcast', pendingRebroadcastId: '42' })
        const event = { currentTarget: ctx.dragged, preventDefault: vi.fn(), stopPropagation: vi.fn() }
        await pool.duplicatePendingRebroadcast.call(ctx, event)
        await pool.deletePendingRebroadcast.call(ctx, event)
        await pool.placePendingRebroadcastFromDrop.call(ctx, element(), 0)
        expect(fetch).not.toHaveBeenCalled()
        expect(window.confirm).not.toHaveBeenCalled()
    })

    test('affiche une émission non régulière et active son déplacement comme programmation ponctuelle', () => {
        const ctx = poolContext()

        ctx.pendingRebroadcastItems = [
            {
                type: 'non_regular_emission',
                id: null,
                emissionId: 125,
                title: 'Émission ponctuelle',
                category: 'Culture',
                durationMinutes: 45,
                assignmentGroupKey: null,
                createdAt: '2026-10-20T10:00:00+02:00',
                previousDiffusions: []
            }
        ]

        pool.renderPendingRebroadcasts.call(ctx)

        const card = ctx.pendingRebroadcastListTarget.querySelector('article')

        expect(card).not.toBeNull()

        // Identification.
        expect(card.dataset.type).toBe('non_regular_emission')
        expect(card.dataset.emissionId).toBe('125')
        expect(card.hasAttribute('data-pending-rebroadcast-id')).toBe(false)

        // Données nécessaires au placement.
        expect(card.dataset.specialItemType).toBe('manual_special')
        expect(card.dataset.emissionDuration).toBe('45')

        // Informations visibles.
        expect(card.textContent).toContain('Émission ponctuelle')
        expect(card.textContent).toContain('Culture')
        expect(card.textContent).toContain('45 min')

        // Distinction visuelle.
        expect(card.classList.contains('pending-rebroadcast-card--non-regular')).toBe(true)
        expect(card.textContent).toContain('Nouvelle émission')

        // Le déplacement utilise le mécanisme des émissions ponctuelles.
        expect(ctx.makeDraggable).toHaveBeenCalledTimes(1)
        expect(ctx.makeDraggable).toHaveBeenCalledWith(card, 'pool')

        // Les actions réservées aux Pending restent indisponibles.
        const duplicateButton = card.querySelector(
            '.pending-rebroadcast-card__duplicate'
        )

        const deleteButton = card.querySelector(
            '.pending-rebroadcast-card__delete'
        )

        expect(duplicateButton).not.toBeNull()
        expect(deleteButton).not.toBeNull()

        expect(duplicateButton.disabled).toBe(true)
        expect(deleteButton.disabled).toBe(true)

        expect(
            card.querySelector('[data-action="click->grid#duplicatePendingRebroadcast"]')
        ).toBeNull()

        expect(
            card.querySelector('[data-action="click->grid#deletePendingRebroadcast"]')
        ).toBeNull()
    })

    test('distingue le déplacement des rediffusions en attente et des nouvelles émissions', () => {
        const ctx = poolContext()

        ctx.pendingRebroadcastItems = [
            {
                ...item,
                type: 'pending_rebroadcast',
                previousDiffusions: []
            },
            {
                type: 'non_regular_emission',
                id: null,
                emissionId: 125,
                title: 'Nouvelle émission',
                category: 'Culture',
                durationMinutes: 45,
                assignmentGroupKey: null,
                previousDiffusions: []
            }
        ]

        pool.renderPendingRebroadcasts.call(ctx)

        const pendingCard = ctx.pendingRebroadcastListTarget.querySelector(
            '[data-type="pending_rebroadcast"]'
        )

        const nonRegularCard = ctx.pendingRebroadcastListTarget.querySelector(
            '[data-type="non_regular_emission"]'
        )

        expect(pendingCard).not.toBeNull()
        expect(nonRegularCard).not.toBeNull()

        expect(ctx.makeDraggable).toHaveBeenCalledTimes(2)

        expect(ctx.makeDraggable).toHaveBeenCalledWith(
            pendingCard,
            'pending-rebroadcast'
        )

        expect(ctx.makeDraggable).toHaveBeenCalledWith(
            nonRegularCard,
            'pool'
        )

        expect(pendingCard.dataset.pendingRebroadcastId).toBe('42')
        expect(nonRegularCard.dataset.specialItemType).toBe('manual_special')
    })

    test('en lecture seule aucune des deux catégories ne peut être déplacée', () => {
        const ctx = poolContext(true)

        ctx.pendingRebroadcastItems = [
            {
                ...item,
                type: 'pending_rebroadcast'
            },
            {
                type: 'non_regular_emission',
                id: null,
                emissionId: 125,
                title: 'Nouvelle émission',
                category: 'Culture',
                durationMinutes: 45,
                assignmentGroupKey: null,
                previousDiffusions: []
            }
        ]

        pool.renderPendingRebroadcasts.call(ctx)

        const cards = ctx.pendingRebroadcastListTarget.querySelectorAll(
            '.pending-rebroadcast-card'
        )

        expect(cards).toHaveLength(2)

        for (const card of cards) {
            expect(card.draggable).toBe(false)
        }

        expect(ctx.makeDraggable).not.toHaveBeenCalled()
    })

    test('identifie visuellement les nouvelles émissions et les rediffusions à programmer', () => {
        const ctx = poolContext()

        ctx.pendingRebroadcastItems = [
            {
                ...item,
                type: 'pending_rebroadcast',
                previousDiffusions: []
            },
            {
                type: 'non_regular_emission',
                id: null,
                emissionId: 125,
                title: 'Nouvelle émission',
                category: 'Culture',
                durationMinutes: 45,
                assignmentGroupKey: null,
                previousDiffusions: []
            }
        ]

        pool.renderPendingRebroadcasts.call(ctx)

        const pendingCard = ctx.pendingRebroadcastListTarget.querySelector(
            '[data-type="pending_rebroadcast"]'
        )

        const newEmissionCard = ctx.pendingRebroadcastListTarget.querySelector(
            '[data-type="non_regular_emission"]'
        )

        expect(pendingCard).not.toBeNull()
        expect(newEmissionCard).not.toBeNull()

        // Chaque catégorie possède un libellé immédiatement visible.
        expect(
            pendingCard.querySelector('.pending-rebroadcast-card__type')?.textContent
        ).toContain('Rediffusion à programmer')

        expect(
            newEmissionCard.querySelector('.pending-rebroadcast-card__type')?.textContent
        ).toContain('Nouvelle émission')

        // Les deux catégories restent visuellement différenciées.
        expect(
            newEmissionCard.classList.contains('pending-rebroadcast-card--non-regular')
        ).toBe(true)

        expect(
            pendingCard.classList.contains('pending-rebroadcast-card--non-regular')
        ).toBe(false)

        // Le fonctionnement du glisser-déposer reste inchangé.
        expect(ctx.makeDraggable).toHaveBeenCalledWith(
            pendingCard,
            'pending-rebroadcast'
        )

        expect(ctx.makeDraggable).toHaveBeenCalledWith(
            newEmissionCard,
            'pool'
        )
    })

})
