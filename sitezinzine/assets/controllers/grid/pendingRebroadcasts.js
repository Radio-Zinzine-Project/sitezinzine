export async function togglePendingRebroadcastPool() {
    if (!this.hasPendingRebroadcastPanelTarget) {
        return
    }

    const panel = this.pendingRebroadcastPanelTarget
    const isOpen = !panel.hidden

    if (isOpen) {
        panel.hidden = true
        this.updatePendingRebroadcastToggle(false)
        return
    }

    panel.hidden = false
    this.updatePendingRebroadcastToggle(true)

    if (!this.pendingRebroadcastLoaded) {
        await this.loadPendingRebroadcasts()
    }
}

export function updatePendingRebroadcastToggle(isOpen) {
    if (!this.hasPendingRebroadcastToggleTarget) {
        return
    }

    const button = this.pendingRebroadcastToggleTarget

    button.setAttribute('aria-expanded', isOpen ? 'true' : 'false')

    const icon = button.querySelector(
        '[data-pending-rebroadcast-toggle-icon]'
    )

    if (icon) {
        icon.textContent = isOpen ? '▲' : '▼'
    }
}

export async function loadPendingRebroadcasts() {
    if (
        !this.hasPendingRebroadcastListTarget
        || !this.hasPendingRebroadcastStatusTarget
    ) {
        return
    }

    const list = this.pendingRebroadcastListTarget
    const status = this.pendingRebroadcastStatusTarget

    status.textContent = 'Chargement du parc…'
    list.innerHTML = ''

    try {
        const response = await fetch('/admin/pending-rebroadcasts', {
            method: 'GET',
            headers: {
                Accept: 'application/json'
            },
            credentials: 'same-origin'
        })

        let data = null

        try {
            data = await response.json()
        } catch {
            throw new Error(
                'La réponse reçue pour le parc à rediff est invalide.'
            )
        }

        if (!response.ok || data?.success !== true) {
            throw new Error(
                data?.error
                || 'Impossible de charger le parc à rediff.'
            )
        }

        const items = Array.isArray(data.items)
            ? data.items
            : []

        this.pendingRebroadcastItems = items
        this.pendingRebroadcastLoaded = true

        this.updatePendingRebroadcastCount(
            Number.isInteger(data.count)
                ? data.count
                : items.length
        )

        this.renderPendingRebroadcasts()
    } catch (error) {
        console.error(
            'Erreur pendant le chargement du parc à rediff :',
            error
        )

        this.pendingRebroadcastItems = []
        this.pendingRebroadcastLoaded = false

        status.textContent = error instanceof Error
            ? error.message
            : 'Impossible de charger le parc à rediff.'

        list.innerHTML = `
      <div class="pending-rebroadcast-error">
        Le parc n’a pas pu être chargé.

        <button
          type="button"
          class="pending-rebroadcast-retry"
          data-action="click->grid#reloadPendingRebroadcasts"
        >
          Réessayer
        </button>
      </div>
    `
    }
}

export async function reloadPendingRebroadcasts() {
    this.pendingRebroadcastLoaded = false

    await this.loadPendingRebroadcasts()
}

export function searchPendingRebroadcasts() {
    this.renderPendingRebroadcasts()
}

export function renderPendingRebroadcasts() {
    if (
        !this.hasPendingRebroadcastListTarget
        || !this.hasPendingRebroadcastStatusTarget
    ) {
        return
    }

    const list = this.pendingRebroadcastListTarget
    const status = this.pendingRebroadcastStatusTarget

    const items = Array.isArray(this.pendingRebroadcastItems)
        ? this.pendingRebroadcastItems
        : []

    const rawSearch = this.hasPendingRebroadcastSearchTarget
        ? this.pendingRebroadcastSearchTarget.value
        : ''

    const search = normalizeSearch(rawSearch)

    const filteredItems = search === ''
        ? items
        : items.filter((item) => {
            const haystack = normalizeSearch([
                item.title ?? '',
                item.category ?? ''
            ].join(' '))

            return haystack.includes(search)
        })

    if (items.length === 0) {
        status.textContent = 'Aucune émission dans le parc.'
        list.innerHTML = `
            <div class="pending-rebroadcast-empty">
                Le parc à rediff est vide.
            </div>
        `
        return
    }

    if (filteredItems.length === 0) {
        status.textContent = 'Aucun résultat pour cette recherche.'
        list.innerHTML = `
            <div class="pending-rebroadcast-empty">
                Aucune émission du parc ne correspond à la recherche.
            </div>
        `
        return
    }

    status.textContent = search === ''
        ? `${filteredItems.length} émission${filteredItems.length > 1 ? 's' : ''} dans le parc.`
        : `${filteredItems.length} résultat${filteredItems.length > 1 ? 's' : ''}.`

    list.innerHTML = filteredItems
        .map((item) => buildPendingRebroadcastCard(this, item))
        .join('')

    if (!this.isReadonly()) {
        list
            .querySelectorAll('.pending-rebroadcast-card')
            .forEach((card) => {
                this.makeDraggable(
                    card,
                    'pending-rebroadcast'
                )
            })
    }
}

export async function placePendingRebroadcastFromDrop(
    dayEl,
    startIndex
) {
    const card = this.dragged

    if (
        !card
        || card.dataset.source !== 'pending-rebroadcast'
        || this.isReadonly()
    ) {
        return
    }

    const pendingRebroadcastId = Number.parseInt(
        card.dataset.pendingRebroadcastId || '',
        10
    )

    if (
        Number.isNaN(pendingRebroadcastId)
        || pendingRebroadcastId <= 0
    ) {
        alert(
            'Cette rediffusion en attente ne possède pas d’identifiant valide.'
        )
        return
    }

    const startsAt = this.getStartsAtFromDrop(
        dayEl,
        startIndex
    )

    try {
        const response = await fetch(
            `/admin/pending-rebroadcasts/${pendingRebroadcastId}/place`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    startsAt
                })
            }
        )

        let data = null

        try {
            data = await response.json()
        } catch {
            throw new Error(
                'La réponse reçue lors du placement de la rediffusion est invalide.'
            )
        }

        if (!response.ok || data?.success !== true) {
            throw new Error(
                data?.error
                || 'Impossible de placer cette rediffusion.'
            )
        }

        /*
         * Le service métier a maintenant :
         * - créé la manual_rebroadcast ;
         * - conservé son assignmentGroupKey ;
         * - traité la Pending selon les règles du parc.
         *
         * On ne fabrique donc aucun post-it localement.
         */
        window.location.reload()
    } catch (error) {
        console.error(
            'Erreur pendant le placement de la rediffusion :',
            error
        )

        alert(
            error instanceof Error
                ? error.message
                : 'Impossible de placer cette rediffusion.'
        )
    }
}

export function updatePendingRebroadcastCount(count) {
    if (!this.hasPendingRebroadcastCountTarget) {
        return
    }

    const safeCount = Number.isFinite(Number(count))
        ? Math.max(0, Number(count))
        : 0

    this.pendingRebroadcastCountTarget.textContent =
        String(safeCount)
}

export function updatePendingRebroadcastAction(
    postit = this.selectedPostit
) {
    if (
        !this.hasPendingRebroadcastActionTarget
        || !this.hasPendingRebroadcastActionButtonTarget
    ) {
        return
    }

    const container = this.pendingRebroadcastActionTarget
    const button = this.pendingRebroadcastActionButtonTarget

    container.style.display = 'none'

    button.disabled = false
    button.textContent = 'Envoyer au parc à rediff'
    button.removeAttribute('aria-disabled')

    if (
        !postit
        || this.currentMode !== 'regular'
        || this.isReadonly()
    ) {
        return
    }

    const isManualDraft =
        postit.dataset.isManualDraft === 'true'

    const isGhost =
        postit.dataset.canRestore === 'true'
        || postit.dataset.isBlocking === 'false'

    const draftId = Number.parseInt(
        postit.dataset.draftId || '',
        10
    )

    const broadcastRank = Number.parseInt(
        postit.dataset.broadcastRank || '1',
        10
    )

    const assignedEmissionTitle =
        postit.dataset.assignedEmissionTitle || ''

    if (
        isManualDraft
        || isGhost
        || Number.isNaN(draftId)
        || draftId <= 0
        || broadcastRank !== 1
        || assignedEmissionTitle === ''
    ) {
        return
    }

    container.style.display = 'block'

    const assignmentGroupKey =
        postit.dataset.assignmentGroupKey || ''

    if (!assignmentGroupKey) {
        return
    }

    const items = Array.isArray(this.pendingRebroadcastItems)
        ? this.pendingRebroadcastItems
        : []

    const alreadyPending = items.some(
        (item) =>
            String(item.assignmentGroupKey || '')
            === assignmentGroupKey
    )

    if (!alreadyPending) {
        return
    }

    button.disabled = true
    button.setAttribute('aria-disabled', 'true')
    button.textContent = 'Déjà dans le parc à rediff'
}

export async function sendSelectedToPendingRebroadcast() {
    if (
        this.isReadonly()
        || !this.selectedPostit
        || !this.hasPendingRebroadcastActionButtonTarget
    ) {
        return
    }

    const postit = this.selectedPostit

    const draftId = Number.parseInt(
        postit.dataset.draftId || '',
        10
    )

    const broadcastRank = Number.parseInt(
        postit.dataset.broadcastRank || '1',
        10
    )

    const isManualDraft =
        postit.dataset.isManualDraft === 'true'

    const isGhost =
        postit.dataset.canRestore === 'true'
        || postit.dataset.isBlocking === 'false'

    if (
        Number.isNaN(draftId)
        || draftId <= 0
        || broadcastRank !== 1
        || isManualDraft
        || isGhost
    ) {
        this.updatePendingRebroadcastAction(postit)
        return
    }

    const button =
        this.pendingRebroadcastActionButtonTarget

    button.disabled = true
    button.setAttribute('aria-disabled', 'true')
    button.textContent = 'Envoi au parc…'

    try {
        const response = await fetch(
            '/admin/pending-rebroadcasts',
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    draftId
                })
            }
        )

        let data = null

        try {
            data = await response.json()
        } catch {
            throw new Error(
                'La réponse reçue lors de l’envoi au parc est invalide.'
            )
        }

        /*
         * Le 409 signifie que le back nous apprend que le groupe
         * est déjà présent dans le parc.
         *
         * Ce n'est pas une erreur fonctionnelle pour l'interface :
         * on resynchronise le parc puis on affiche l'état désactivé.
         */
        if (response.status === 409) {
            await this.reloadPendingRebroadcasts()
            this.updatePendingRebroadcastAction(postit)
            return
        }

        if (!response.ok || data?.success !== true) {
            throw new Error(
                data?.error
                || 'Impossible d’envoyer cette émission au parc à rediff.'
            )
        }

        await this.reloadPendingRebroadcasts()

        /*
         * Le contrôleur retourne l'assignmentGroupKey créé.
         * On le conserve également sur le post-it sélectionné afin
         * que son état reste immédiatement synchronisé.
         */
        if (
            typeof data.assignmentGroupKey === 'string'
            && data.assignmentGroupKey !== ''
        ) {
            postit.dataset.assignmentGroupKey =
                data.assignmentGroupKey
        }

        this.updatePendingRebroadcastAction(postit)
    } catch (error) {
        console.error(
            'Erreur pendant l’envoi au parc à rediff :',
            error
        )

        button.disabled = false
        button.removeAttribute('aria-disabled')
        button.textContent = 'Envoyer au parc à rediff'

        alert(
            error instanceof Error
                ? error.message
                : 'Impossible d’envoyer cette émission au parc à rediff.'
        )
    }
}

export async function duplicatePendingRebroadcast(event) {
    event.preventDefault()
    event.stopPropagation()

    if (this.isReadonly()) {
        return
    }

    const button = event.currentTarget

    const pendingRebroadcastId = Number.parseInt(
        button.dataset.pendingRebroadcastId || '',
        10
    )

    if (
        Number.isNaN(pendingRebroadcastId)
        || pendingRebroadcastId <= 0
    ) {
        alert(
            'Cette rediffusion en attente ne possède pas d’identifiant valide.'
        )
        return
    }

    const originalLabel = button.textContent

    button.disabled = true
    button.textContent = 'Duplication…'

    try {
        const response = await fetch(
            `/admin/pending-rebroadcasts/${pendingRebroadcastId}/duplicate`,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json'
                },
                credentials: 'same-origin'
            }
        )

        let data = null

        try {
            data = await response.json()
        } catch {
            throw new Error(
                'La réponse reçue lors de la duplication est invalide.'
            )
        }

        if (!response.ok || data?.success !== true) {
            throw new Error(
                data?.error
                || 'Impossible de dupliquer cette rediffusion.'
            )
        }

        await this.reloadPendingRebroadcasts()

        /*
         * Le groupe possède maintenant au moins une Pending.
         * On resynchronise également l'action de la première
         * diffusion régulière si elle est actuellement sélectionnée.
         */
        this.updatePendingRebroadcastAction(
            this.selectedPostit
        )
    } catch (error) {
        console.error(
            'Erreur pendant la duplication de la rediffusion :',
            error
        )

        /*
         * reloadPendingRebroadcasts() reconstruit le DOM en cas
         * de succès. Si nous sommes ici, le bouton d'origine existe
         * toujours et peut donc être réactivé.
         */
        if (button.isConnected) {
            button.disabled = false
            button.textContent = originalLabel
        }

        alert(
            error instanceof Error
                ? error.message
                : 'Impossible de dupliquer cette rediffusion.'
        )
    }
}

export async function deletePendingRebroadcast(event) {
    event.preventDefault()
    event.stopPropagation()

    if (this.isReadonly()) {
        return
    }

    const button = event.currentTarget

    const pendingRebroadcastId = Number.parseInt(
        button.dataset.pendingRebroadcastId || '',
        10
    )

    if (
        Number.isNaN(pendingRebroadcastId)
        || pendingRebroadcastId <= 0
    ) {
        alert(
            'Cette rediffusion en attente ne possède pas d’identifiant valide.'
        )
        return
    }

    const confirmed = window.confirm(
        'Supprimer définitivement cette rediffusion du parc ?'
    )

    if (!confirmed) {
        return
    }

    const originalLabel = button.textContent

    button.disabled = true
    button.textContent = 'Suppression…'

    try {
        const response = await fetch(
            `/admin/pending-rebroadcasts/${pendingRebroadcastId}`,
            {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json'
                },
                credentials: 'same-origin'
            }
        )

        let data = null

        try {
            data = await response.json()
        } catch {
            throw new Error(
                'La réponse reçue lors de la suppression est invalide.'
            )
        }

        if (!response.ok || data?.success !== true) {
            throw new Error(
                data?.error
                || 'Impossible de supprimer cette rediffusion du parc.'
            )
        }

        await this.reloadPendingRebroadcasts()

        this.updatePendingRebroadcastAction(
            this.selectedPostit
        )
    } catch (error) {
        console.error(
            'Erreur pendant la suppression de la rediffusion du parc :',
            error
        )

        if (button.isConnected) {
            button.disabled = false
            button.textContent = originalLabel
        }

        alert(
            error instanceof Error
                ? error.message
                : 'Impossible de supprimer cette rediffusion du parc.'
        )
    }
}

function buildPendingRebroadcastCard(controller, item) {
  const pendingId = Number.parseInt(item.id, 10)
  const emissionId = Number.parseInt(item.emissionId, 10)
  const duration = Number.parseInt(
    item.durationMinutes,
    10
  )

  const safePendingId = Number.isNaN(pendingId)
    ? ''
    : String(pendingId)

  const safeEmissionId = Number.isNaN(emissionId)
    ? ''
    : String(emissionId)

  const safeDuration = Number.isNaN(duration)
    ? 15
    : Math.max(1, duration)

  const title = controller.escapeHtml(
    item.title || 'Émission sans titre'
  )

  const category = controller.escapeHtml(
    item.category || 'Sans catégorie'
  )

  const assignmentGroupKey = controller.escapeHtml(
    item.assignmentGroupKey || ''
  )

  return `
    <article
      class="pending-rebroadcast-card"
      data-pending-rebroadcast-id="${safePendingId}"
      data-emission-id="${safeEmissionId}"
      data-assignment-group-key="${assignmentGroupKey}"
      data-duration="${safeDuration}"
      draggable="false"
      title="Glisser cette rediffusion dans la grille"
    >
      <div class="pending-rebroadcast-card__content">
        <div class="pending-rebroadcast-card__title">
          ${title}
        </div>

        <div class="pending-rebroadcast-card__meta">
          <span>${category}</span>
          <span aria-hidden="true">·</span>
          <span>${safeDuration} min</span>
        </div>
      </div>

      <div class="pending-rebroadcast-card__actions">
        <button
          type="button"
          class="pending-rebroadcast-card__duplicate"
          data-pending-rebroadcast-id="${safePendingId}"
          data-action="click->grid#duplicatePendingRebroadcast"
          draggable="false"
          title="Créer une autre rediffusion en attente"
        >
          Dupliquer
        </button>

        <button
          type="button"
          class="pending-rebroadcast-card__delete"
          data-pending-rebroadcast-id="${safePendingId}"
          data-action="click->grid#deletePendingRebroadcast"
          draggable="false"
          title="Supprimer cette rediffusion du parc"
        >
          Supprimer
        </button>
      </div>
    </article>
  `
}

function normalizeSearch(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim()
        .toLocaleLowerCase('fr-FR')
}