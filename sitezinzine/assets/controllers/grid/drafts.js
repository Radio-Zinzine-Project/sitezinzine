import { postForm, postJson } from './api'

export async function dropOnTrash(event) {
    event.preventDefault()

    const dragged = this.dragged

    if (!dragged) {
        return
    }

    if (this.hasTrashZoneTarget) {
        this.trashZoneTarget.classList.remove('is-active')
    }

    if (!this.canDropInTrash()) {
        return
    }

    const draftId = dragged.dataset.draftId || ''
    const groupKey = dragged.dataset.assignmentGroupKey || ''
    const startsAt = dragged.dataset.startsAt || ''

    if (!draftId) {
        alert('Impossible de supprimer ce draft : identifiant manquant.')
        return
    }

    let deleteMode = 'single'

    if (groupKey) {
        deleteMode = await askDraftDeleteMode()
    } else {
        const confirmed = window.confirm(
            'Supprimer cette programmation ponctuelle ?'
        )

        if (!confirmed) {
            return
        }
    }

    if (!deleteMode) {
        return
    }

    try {
        await postJson('/admin/grid-drafts/delete', {
            draftId,
            deleteMode
        })

        this.saveCurrentMode()
        this.saveScrollTarget(startsAt)
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
        this.saveScrollTarget(startsAt)
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
        let data = null

        if (itemType === 'manual_live') {
            const categoryId = this.dragged.dataset.categoryId || ''

            if (!categoryId) {
                throw new Error(
                    'Choisis d’abord une catégorie pour créer un direct.'
                )
            }

            data = await postForm('/admin/grid-drafts/manual-live', {
                categoryId,
                startsAt
            })
        } else {
            const emissionId = this.dragged.dataset.emissionId || ''
            const durationMinutes =
                this.dragged.dataset.emissionDuration || ''

            if (!emissionId) {
                throw new Error('Émission invalide.')
            }

            data = await postForm('/admin/grid-drafts/manual', {
                emissionId,
                startsAt,
                durationMinutes: String(durationMinutes || ''),
                draftType: 'manual_special'
            })
        }

        this.saveScrollTarget(
            startsAt,
            data?.draftId ?? null
        )

        window.location.reload()

    } catch (error) {
        alert(
            error.message ||
            'Impossible de créer la programmation.'
        )
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

    export async function openRebroadcastModal() {
        if (!this.selectedPostit) {
            return
        }

        let existingRebroadcasts = []

        try {
            const response = await fetch(
                `/admin/grid-drafts/${this.selectedPostit.dataset.draftId}/rebroadcasts`
            )

            const data = await response.json()

            existingRebroadcasts =
                Array.isArray(data.items)
                    ? data.items
                    : []

        } catch {
            existingRebroadcasts = []
        }

        const overlay = document.createElement('div')
        overlay.className = 'rebroadcast-modal-overlay'

        overlay.innerHTML = `
        <div class="rebroadcast-modal" role="dialog" aria-modal="true">
            <h3>Ajouter des rediffs ponctuelles</h3>

            <p>
                Choisis les dates et heures des rediffusions à ajouter.
            </p>
            ${existingRebroadcasts.length
                ? `
            <div class="existing-rebroadcasts">
                <h4>Rediffusions existantes</h4>

            ${existingRebroadcasts.map((item) => `
                <div class="existing-rebroadcast">
                    <div class="existing-rebroadcast__info">
                        <strong>
                            ↺ Rediff ${item.number}
                        </strong>

                        <small>
                            ${formatRebroadcastDate(item.startsAt)}
                        </small>
                    </div>

                    <button
                        type="button"
                        class="btn-arbitration btn-arbitration--danger"
                        data-delete-rebroadcast-id="${item.id}"
                    >
                        🗑
                    </button>
                </div>
            `).join('')}
            </div>
            `
                : ''
            }
            <div class="manual-rebroadcasts-form">
                <div class="manual-rebroadcast-row">
                    <label>Rediffusion ${existingRebroadcasts.length + 1}</label>
                    <input type="date" data-rebroadcast-date>
                    <input type="time" step="900" data-rebroadcast-time>
                </div>
            </div>

            <button
                type="button"
                class="btn-arbitration"
                data-add-rebroadcast-row
            >
                Ajouter une autre rediff
            </button>

            <button
                type="button"
                class="btn-arbitration"
                data-confirm-rebroadcasts
            >
                Valider
            </button>

            <button
                type="button"
                class="btn-arbitration btn-arbitration--danger"
                data-close-rebroadcasts
            >
                Annuler
            </button>
        </div>
    `

        const form = overlay.querySelector('.manual-rebroadcasts-form')
        const addButton = overlay.querySelector('[data-add-rebroadcast-row]')
        const confirmButton = overlay.querySelector('[data-confirm-rebroadcasts]')
        const closeButton = overlay.querySelector('[data-close-rebroadcasts]')

        overlay
            .querySelectorAll('[data-delete-rebroadcast-id]')
            .forEach((button) => {
                button.addEventListener('click', async () => {
                    const draftId = button.dataset.deleteRebroadcastId || ''

                    if (!draftId) {
                        return
                    }

                    const confirmed = window.confirm(
                        'Supprimer cette rediffusion ponctuelle ?'
                    )

                    if (!confirmed) {
                        return
                    }

                    try {
                        await postJson('/admin/grid-drafts/delete', {
                            draftId,
                            deleteMode: 'single'
                        })

                        this.saveScrollTarget(startsAt)
                        window.location.reload()

                    } catch (error) {
                        alert(
                            error.message ||
                            'Impossible de supprimer cette rediffusion.'
                        )
                    }
                })
            })

        const close = () => {
            overlay.remove()
        }

        addButton.addEventListener('click', () => {
            const index =
                existingRebroadcasts.length +
                form.querySelectorAll('.manual-rebroadcast-row').length +
                1

            const row = document.createElement('div')
            row.className = 'manual-rebroadcast-row'

            row.innerHTML = `
            <label>Rediffusion ${index}</label>
            <input type="date" data-rebroadcast-date>
            <input type="time" step="900" data-rebroadcast-time>
        `

            form.appendChild(row)
        })

        confirmButton.addEventListener('click', async () => {
            const rows = form.querySelectorAll(
                '.manual-rebroadcast-row'
            )

            const rebroadcasts = []

            rows.forEach((row) => {
                const date =
                    row.querySelector(
                        '[data-rebroadcast-date]'
                    )?.value || ''

                const time =
                    row.querySelector(
                        '[data-rebroadcast-time]'
                    )?.value || ''

                if (date && time) {
                    rebroadcasts.push(
                        `${date} ${time}:00`
                    )
                }
            })

            if (!rebroadcasts.length) {
                alert(
                    'Ajoute au moins une rediffusion.'
                )
                return
            }

            try {
                await postJson(
                    '/admin/grid-drafts/rebroadcasts',
                    {
                        draftId:
                            this.selectedPostit.dataset.draftId,
                        rebroadcasts
                    }
                )

                close()

                window.location.reload()

            } catch (error) {
                alert(
                    error.message ||
                    'Impossible de créer les rediffusions.'
                )
            }
        })

        closeButton.addEventListener('click', close)

        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                close()
            }
        })

        document.body.appendChild(overlay)
    }

    function askDraftDeleteMode() {
        return new Promise((resolve) => {
            const overlay = document.createElement('div')
            overlay.className = 'rebroadcast-modal-overlay'

            overlay.innerHTML = `
            <div class="rebroadcast-modal" role="dialog" aria-modal="true">
                <h3>Supprimer une programmation liée</h3>

                <p>
                    Cette programmation fait partie d’un groupe avec des rediffusions ponctuelles.
                    Que veux-tu supprimer ?
                </p>

                <div class="rebroadcast-modal__actions">
                    <button type="button" data-delete-mode="single">
                        Supprimer uniquement ce créneau
                    </button>

                    <button type="button" data-delete-mode="rebroadcasts">
                        Supprimer toutes les rediffs du groupe
                    </button>

                    <button type="button" data-delete-mode="group">
                        Supprimer la 1re diffusion et toutes les rediffs
                    </button>

                    <button type="button" data-delete-mode="">
                        Annuler
                    </button>
                </div>
            </div>
        `

            document.body.appendChild(overlay)

            overlay.querySelectorAll('[data-delete-mode]').forEach((button) => {
                button.addEventListener('click', () => {
                    const mode = button.dataset.deleteMode || ''
                    overlay.remove()
                    resolve(mode)
                })
            })

            overlay.addEventListener('click', (event) => {
                if (event.target === overlay) {
                    overlay.remove()
                    resolve('')
                }
            })
        })
    }

    function formatRebroadcastDate(value) {
        if (!value) {
            return ''
        }

        const normalized = value.replace(' ', 'T')
        const date = new Date(normalized)

        if (Number.isNaN(date.getTime())) {
            return value
        }

        return date.toLocaleString('fr-FR', {
            weekday: 'short',
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        })
    }