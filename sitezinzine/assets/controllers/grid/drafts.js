import { postForm, postJson } from './api'

export async function dropOnTrash(event) {
    event.preventDefault()

    if (this.hasTrashZoneTarget) {
        this.trashZoneTarget.classList.remove('is-active')
    }

    if (!this.canDropInTrash()) {
        return
    }

    const draftId = this.dragged.dataset.draftId || ''

    if (!draftId) {
        alert('Impossible de supprimer ce draft : identifiant manquant.')
        return
    }

    const confirmed = window.confirm(
        'Supprimer cette programmation ponctuelle ?'
    )

    if (!confirmed) {
        return
    }

    try {
        await postJson('/admin/grid-drafts/delete', {
            draftId
        })

        this.saveCurrentMode()
        window.location.reload()

    } catch (error) {
        alert(
            error.message ||
            'Erreur lors de la suppression du draft.'
        )
    }
}

    export async function moveManualDraftFromDrop(dayEl, startIndex) {
        if (!this.dragged) {
            return
        }

        const draftId = this.dragged.dataset.draftId || ''
        const startsAt = this.getStartsAtFromDrop(dayEl, startIndex)

        if (!draftId || !startsAt) {
            alert('Impossible de déplacer ce draft : informations manquantes.')
            return
        }

        try {
            await postForm('/admin/grid-drafts/move', {
                draftId,
                startsAt
            })

            this.saveCurrentMode()
            window.location.reload()

        } catch (error) {
            alert(
                error.message ||
                'Erreur lors du déplacement du draft.'
            )
        }
    }

    export async function createSpecialDraftFromDrop(dayEl, startIndex) {
        if (!this.dragged) {
            return
        }

        const startsAt = this.getStartsAtFromDrop(dayEl, startIndex)
        const itemType = this.dragged.dataset.specialItemType || ''

        if (!startsAt || !itemType) {
            alert('Impossible de déterminer le créneau de dépôt.')
            return
        }

        try {
            if (itemType === 'manual_live') {
                const categoryId = this.dragged.dataset.categoryId || ''

                if (!categoryId) {
                    throw new Error('Choisis d’abord une catégorie pour créer un direct.')
                }

                await postForm('/admin/grid-drafts/manual-live', {
                    categoryId,
                    startsAt
                })
            } else {
                const emissionId = this.dragged.dataset.emissionId || ''
                const durationMinutes = this.dragged.dataset.emissionDuration || ''

                if (!emissionId) {
                    throw new Error('Émission invalide.')
                }

                await postForm('/admin/grid-drafts/manual', {
                    emissionId,
                    startsAt,
                    durationMinutes: String(durationMinutes || ''),
                    draftType: 'manual_special'
                })
            }

            window.location.reload()
        } catch (error) {
            alert(error.message || 'Impossible de créer la programmation.')
        }
    }

    export function canDropInTrash() {
        return !!(
            this.currentMode === 'special' &&
            this.dragged &&
            this.dragged.dataset.source === 'grid' &&
            this.dragged.dataset.isManualDraft === 'true'
        )
    }
