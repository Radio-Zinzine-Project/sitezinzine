import { beforeEach, describe, expect, test, vi } from 'vitest'
import flatpickr from 'flatpickr'
import { French } from 'flatpickr/dist/l10n/fr.js'
import FlatpickrController from '../../../assets/controllers/flatpickr_controller'
import { mountController, flushDom } from '../support/dom'

vi.mock('flatpickr', () => ({ default: vi.fn() }))
vi.mock('flatpickr/dist/l10n/fr.js', () => ({ French: { locale: 'test-fr' } }))
let picker
beforeEach(() => {
    picker = { destroy: vi.fn() }
    flatpickr.mockReturnValue(picker)
})

describe('Intégration du sélecteur de date, Flatpickr simulé', () => {
    test('transmet les valeurs Stimulus et le locale sans monter de widget réel', async () => {
        const { root } = await mountController('flatpickr', FlatpickrController,
            '<input data-controller="flatpickr" data-flatpickr-enable-time-value="true" data-flatpickr-date-format-value="Y-m-d H:i" data-flatpickr-alt-format-value="d/m/Y H:i">')
        expect(flatpickr).toHaveBeenCalledWith(root, expect.objectContaining({
            enableTime: true, dateFormat: 'Y-m-d H:i', altFormat: 'd/m/Y H:i', locale: French,
        }))
    })

    test('une reconnexion détruit l’ancien widget avant d’en créer un nouveau', async () => {
        const { controller, root } = await mountController('flatpickr', FlatpickrController, '<input data-controller="flatpickr">')
        const old = { destroy: vi.fn() }; root._flatpickr = old
        controller.connect()
        expect(old.destroy).toHaveBeenCalledOnce()
        expect(old.destroy.mock.invocationCallOrder[0]).toBeLessThan(flatpickr.mock.invocationCallOrder[1])
        root.remove(); await flushDom()
        expect(picker.destroy).toHaveBeenCalledOnce()
    })
})
