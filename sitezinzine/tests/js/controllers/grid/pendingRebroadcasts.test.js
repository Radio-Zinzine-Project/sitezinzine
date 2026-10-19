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

describe('Parc à rediffusion', () => {
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
        vi.spyOn(console, 'error').mockImplementation(() => {})
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
        vi.spyOn(console, 'error').mockImplementation(() => {})
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
        vi.spyOn(console, 'error').mockImplementation(() => {})
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
})
