import { postForm } from './api'
import {
    getSlotData,
    validateSlotData
} from './utils'

export async function cancelOccurrence() {
    if (!this.selectedPostit) {
        return
    }

    const {
        slotId,
        startsAt
    } = getSlotData(this.selectedPostit)

    if (
        !validateSlotData(
            slotId,
            startsAt,
            'Informations incomplètes pour annuler cette occurrence.'
        )
    ) {
        return
    }

    const rebroadcast =
        await this.askRebroadcastStrategy('annuler')

    const confirmed =
        window.confirm('Annuler cette occurrence de la grille ?')

    if (!confirmed) {
        return
    }

    try {
        await postForm('/admin/grille/cancel-occurrence', {
            slotId,
            startsAt,
            rebroadcastStrategy: rebroadcast.strategy,
            rebroadcastTargets: JSON.stringify(rebroadcast.targets || [])
        })

        window.location.reload()

    } catch (error) {
        alert(`Erreur lors de l’annulation : ${error.message}`)
    }
}

export async function restoreOccurrence() {
    if (!this.selectedPostit) {
        return
    }

    const { slotId } = getSlotData(this.selectedPostit)

    const originalStartsAt =
        this.selectedPostit.dataset.originalStartsAt ||
        this.selectedPostit.dataset.startsAt ||
        ''

    if (
        !validateSlotData(
            slotId,
            originalStartsAt,
            'Informations incomplètes pour restaurer ce créneau.'
        )
    ) {
        return
    }

    const restoreStrategy =
        await this.askRestoreRebroadcastStrategy()

    try {
        const data = await postForm(
            '/admin/grille/restore-occurrence',
            {
                slotId,
                originalStartsAt,
                restoreLinkedRebroadcasts:
                    restoreStrategy.restoreLinkedRebroadcasts ? '1' : '0'
            }
        )

        if (data.targetWeekStart) {
            window.location.href =
                `/admin/grille/${data.targetWeekStart}`

            return
        }

        window.location.reload()

    } catch (error) {
        alert(`Erreur : ${error.message}`)
    }
}

export async function submitWeekReschedule(direction) {
    if (!this.selectedPostit) {
        return
    }

    const {
        slotId,
        startsAt
    } = getSlotData(this.selectedPostit)

    if (
        !validateSlotData(
            slotId,
            startsAt,
            'Informations incomplètes pour déplacer ce créneau.'
        )
    ) {
        return
    }

    const rebroadcast =
        await this.askRebroadcastStrategy('déplacer')

    try {
        const data = await postForm('/admin/grille/reschedule-week', {
            slotId,
            startsAt,
            direction,
            rebroadcastStrategy: rebroadcast.strategy,
            rebroadcastTargets: JSON.stringify(rebroadcast.targets || [])
        })

        if (!data.targetWeekStart) {
            throw new Error('Semaine cible manquante')
        }

        window.location.href = `/admin/grille/${data.targetWeekStart}`
    } catch (error) {
        alert(`Erreur lors du déplacement : ${error.message}`)
    }
}

export async function submitCustomReschedule() {
    if (!this.selectedPostit) {
        return
    }

    const {
        slotId,
        startsAt
    } = getSlotData(this.selectedPostit)

    const dateInput =
        this.arbitrationActionsTarget.querySelector(
            '[data-grid-custom-date]'
        )

    const timeInput =
        this.arbitrationActionsTarget.querySelector(
            '[data-grid-custom-time]'
        )

    const newDate = dateInput?.value || ''
    const newTime = timeInput?.value || ''

    if (
        !validateSlotData(
            slotId,
            startsAt,
            'Informations incomplètes pour déplacer ce créneau.'
        )
    ) {
        return
    }

    if (!newDate || !newTime) {
        alert('Merci de renseigner une date et une heure.')
        return
    }

    const rebroadcast =
        await this.askRebroadcastStrategy('déplacer')

    try {
        const data = await postForm(
            '/admin/grille/reschedule-custom',
            {
                slotId,
                startsAt,
                newDate,
                newTime,
                rebroadcastStrategy: rebroadcast.strategy,
                rebroadcastTargets: JSON.stringify(rebroadcast.targets || [])
            }
        )

        if (!data.targetWeekStart) {
            throw new Error(
                'Semaine cible manquante'
            )
        }

        window.location.href =
            `/admin/grille/${data.targetWeekStart}`

    } catch (error) {
        alert(
            `Erreur lors du déplacement personnalisé : ${error.message}`
        )
    }
}

export async function clearReschedule() {
    if (!this.selectedPostit) {
        return
    }

    const slotId = this.selectedPostit.dataset.slotId || ''
    const originalStartsAt = this.selectedPostit.dataset.originalStartsAt || ''

    if (
        !validateSlotData(
            slotId,
            originalStartsAt,
            'Informations incomplètes pour revenir au créneau d’origine.'
        )
    ) {
        return
    }

    const restoreStrategy =
        await this.askRestoreRebroadcastStrategy()

    try {
        const data = await postForm('/admin/grille/clear-reschedule', {
            slotId,
            originalStartsAt,
            restoreLinkedRebroadcasts:
                restoreStrategy.restoreLinkedRebroadcasts ? '1' : '0'
        })

        if (!data.targetWeekStart) {
            throw new Error('Semaine cible manquante')
        }

        window.location.href = `/admin/grille/${data.targetWeekStart}`
    } catch (error) {
        alert(`Erreur lors du retour au créneau d’origine : ${error.message}`)
    }
}

export async function callConflictReschedule(item, direction) {
    const slotId = item.slotId || ''
    const startsAt = item.originalStartsAt || item.startsAt || ''

    if (
        !validateSlotData(
            slotId,
            startsAt,
            'Informations incomplètes pour déplacer cette occurrence en conflit.'
        )
    ) {
        return
    }

    const rebroadcast =
        await this.askRebroadcastStrategy('déplacer')

    try {
        const data = await postForm('/admin/grille/reschedule-week', {
            slotId,
            startsAt,
            direction,
            rebroadcastStrategy: rebroadcast.strategy,
            rebroadcastTargets: JSON.stringify(rebroadcast.targets || [])
        })

        if (!data.targetWeekStart) {
            throw new Error('Semaine cible manquante')
        }

        window.location.href = `/admin/grille/${data.targetWeekStart}`
    } catch (error) {
        alert(`Erreur lors du déplacement du conflit : ${error.message}`)
    }
}

export async function callConflictCancel(item) {
    const slotId = item.slotId || ''
    const startsAt = item.originalStartsAt || item.startsAt || ''

    if (
        !validateSlotData(
            slotId,
            startsAt,
            'Informations incomplètes pour annuler cette occurrence en conflit.'
        )
    ) {
        return
    }

    const rebroadcast =
        await this.askRebroadcastStrategy('annuler')

    const confirmed = window.confirm('Annuler cette occurrence en conflit ?')

    if (!confirmed) {
        return
    }

    try {
        await postForm('/admin/grille/cancel-occurrence', {
            slotId,
            startsAt,
            rebroadcastStrategy: rebroadcast.strategy,
            rebroadcastTargets: JSON.stringify(rebroadcast.targets || [])
        })

        window.location.reload()
    } catch (error) {
        alert(`Erreur lors de l’annulation du conflit : ${error.message}`)
    }
}
