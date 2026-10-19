import { describe, expect, test } from 'vitest'
import SlotController from '../../../assets/controllers/programmation_rule_slot_controller'
import { mountController } from '../support/dom'

const identifier = 'programmation-rule-slot'
function markup(type) {
    return `<div data-controller="${identifier}">
        <select data-${identifier}-target="recurrenceType" data-action="change->${identifier}#toggleRecurrenceFields">
            <option value="weekly" ${type === 'weekly' ? 'selected' : ''}>Hebdo</option>
            <option value="monthly" ${type === 'monthly' ? 'selected' : ''}>Mensuel</option>
        </select>
        <div data-${identifier}-target="monthlyOccurrenceWrapper"><input data-${identifier}-target="monthlyOccurrence" value="4"></div>
        <div data-${identifier}-target="monthIntervalWrapper"><input data-${identifier}-target="monthInterval" value=""></div>
        <div data-${identifier}-target="weekOffsetWrapper"><input data-${identifier}-target="weekOffset" value="2"></div>
    </div>`
}

describe('Formulaire de créneau récurrent', () => {
    test('le mensuel active les champs avec intervalle par défaut et conserve weekOffset', async () => {
        const { controller } = await mountController(identifier, SlotController, markup('monthly'))
        expect(controller.monthlyOccurrenceTarget.disabled).toBe(false)
        expect(controller.monthIntervalTarget.value).toBe('1')
        expect(controller.monthlyOccurrenceWrapperTarget.style.display).toBe('block')
        expect(controller.weekOffsetTarget.value).toBe('2')
        expect(controller.weekOffsetWrapperTarget.style.display).toBe('block')
    })

    test('passer en hebdomadaire efface les données mensuelles mais garde le décalage', async () => {
        const { controller } = await mountController(identifier, SlotController, markup('monthly'))
        controller.monthIntervalTarget.value = '3'
        controller.recurrenceTypeTarget.value = 'weekly'
        controller.recurrenceTypeTarget.dispatchEvent(new Event('change', { bubbles: true }))
        expect(controller.monthlyOccurrenceTarget.value).toBe('')
        expect(controller.monthlyOccurrenceTarget.disabled).toBe(true)
        expect(controller.monthIntervalTarget.value).toBe('1')
        expect(controller.monthIntervalTarget.disabled).toBe(true)
        expect(controller.weekOffsetTarget.disabled).toBe(false)
        expect(controller.weekOffsetTarget.value).toBe('2')
    })
})
