import { describe, expect, test, vi } from 'vitest'
import { getJson, postForm, postJson } from '../../../../assets/controllers/grid/api'
import { jsonResponse } from '../../support/dom'

describe('Contrats HTTP de grille', () => {
    test('GET transmet la requête AJAX et retourne les données', async () => {
        fetch.mockResolvedValue(jsonResponse({ items: [1] }))
        expect(await getJson('/candidates')).toEqual({ items: [1] })
        expect(fetch).toHaveBeenCalledWith('/candidates', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
    })

    test.each([['form', postForm], ['JSON', postJson]])('%s envoie le payload et exige success', async (format, send) => {
        const payload = { title: 'été & direct', slotId: '12' }
        fetch.mockResolvedValue(jsonResponse({ success: true, draftId: 42 }))
        expect(await send('/assign', payload)).toEqual({ success: true, draftId: 42 })
        const [, options] = fetch.mock.calls[0]
        expect(options.method).toBe('POST')
        expect(options.headers['X-Requested-With']).toBe('XMLHttpRequest')
        expect(format === 'JSON' ? JSON.parse(options.body) : Object.fromEntries(options.body)).toEqual(payload)
        expect(options.headers['Content-Type']).toContain(format === 'JSON' ? 'application/json' : 'application/x-www-form-urlencoded')
    })

    test.each([getJson, postForm, postJson])('propage une erreur HTTP lisible', async send => {
        fetch.mockResolvedValue(jsonResponse({ error: 'Conflit de programmation' }, false))
        await expect(send('/conflict', {})).rejects.toThrow('Conflit de programmation')
    })

    test.each([postForm, postJson])('un HTTP 200 avec success=false reste un refus métier', async send => {
        fetch.mockResolvedValue(jsonResponse({ success: false, error: 'Créneau verrouillé' }))
        await expect(send('/locked', {})).rejects.toThrow('Créneau verrouillé')
    })

    test('un POST avec JSON illisible produit une erreur contrôlée', async () => {
        fetch.mockResolvedValue({ ok: true, json: vi.fn().mockRejectedValue(new SyntaxError('JSON')) })
        await expect(postJson('/broken', {})).rejects.toThrow('Erreur inconnue')
    })

    test('une panne réseau reste une erreur et ne devient pas un succès', async () => {
        fetch.mockRejectedValue(new Error('Hors ligne'))
        await expect(postForm('/assign', {})).rejects.toThrow('Hors ligne')
    })
})
