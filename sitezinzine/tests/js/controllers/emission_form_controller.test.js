import { describe, expect, test, vi } from 'vitest'
import EmissionFormController from '../../../assets/controllers/emission_form_controller'
import { mountController, jsonResponse, deferred, flushDom } from '../support/dom'

const markup = `<form data-controller="emission-form" data-emission-form-users-url-value="/admin/emission/users/0" data-emission-form-emission-id-value="42">
    <select data-emission-form-target="categorie"><option value="7" data-editeur-id="2" data-duree="45">Catégorie</option><option value="8">Autre</option></select>
    <select data-emission-form-target="editeur"><option value="1">A</option><option value="2">B</option></select>
    <input data-emission-form-target="duree"><select multiple data-emission-form-target="users"><option value="99">Ancien</option></select>
</form>`

describe('Formulaire d’émission', () => {
    test('les valeurs de catégorie mettent à jour éditeur et durée via change natif', async () => {
        const { controller, root } = await mountController('emission-form', EmissionFormController, markup)
        expect(controller.editeurTarget.value).toBe('2')
        expect(controller.dureeTarget.value).toBe('45')
        const changed = vi.fn(); root.addEventListener('change', changed)
        controller.syncCategoryDefaults()
        expect(changed).toHaveBeenCalledTimes(2)
    })

    test('la réponse construit les groupes/personnes en conservant sélection et statut', async () => {
        const { controller } = await mountController('emission-form', EmissionFormController, markup)
        fetch.mockResolvedValue(jsonResponse({ users: [
            { id: 1, label: '<img src=x>', selected: true, status: 'current', group: 'Titulaires' },
            { id: 2, label: 'Historique', selected: false, group: 'Historique' },
        ] }))
        await controller.categoryChanged()
        const url = new URL(fetch.mock.calls[0][0])
        expect(url.pathname).toBe('/admin/emission/users/7')
        expect(url.searchParams.get('emissionId')).toBe('42')
        expect(controller.usersTarget.querySelector('img')).toBeNull()
        expect(controller.usersTarget.querySelectorAll('optgroup')).toHaveLength(2)
        expect(controller.usersTarget.selectedOptions[0].textContent).toBe('<img src=x>')
        expect(controller.usersTarget.selectedOptions[0].dataset.status).toBe('current')
        expect(controller.usersTarget.querySelector('[value="99"]')).toBeNull()
    })

    test('un échec HTTP conserve la sélection existante', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})
        const { controller } = await mountController('emission-form', EmissionFormController, markup)
        fetch.mockResolvedValue(jsonResponse({}, false))
        await controller.refreshUsers()
        expect(controller.usersTarget.querySelector('[value="99"]')).not.toBeNull()
    })

    test('changer de catégorie annule la requête précédente et disconnect annule la suivante', async () => {
        const { controller, root } = await mountController('emission-form', EmissionFormController, markup)
        const first = deferred(); const second = deferred()
        fetch.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)
        const loadingFirst = controller.refreshUsers()
        const firstSignal = fetch.mock.calls[0][1].signal
        controller.categorieTarget.value = '8'
        const loadingSecond = controller.refreshUsers()
        const secondSignal = fetch.mock.calls[1][1].signal
        expect(firstSignal.aborted).toBe(true)
        root.remove()
        await flushDom()
        expect(secondSignal.aborted).toBe(true)
        first.reject(new DOMException('Aborted', 'AbortError'))
        second.reject(new DOMException('Aborted', 'AbortError'))
        await Promise.all([loadingFirst, loadingSecond])
    })
})
