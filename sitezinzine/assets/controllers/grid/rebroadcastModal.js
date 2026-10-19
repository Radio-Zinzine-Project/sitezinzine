import { getJson } from './api'
import { escapeHtml } from './utils'

export function askRebroadcastStrategy(
  context,
  actionType = 'week'
) {
  if (!context.selectedPostit) {
    return Promise.resolve({
      strategy: 'keep',
      targets: []
    })
  }

  const broadcastRank = parseInt(
    context.selectedPostit.dataset.broadcastRank || '1',
    10
  )

  if (broadcastRank > 1) {
    return Promise.resolve({
      strategy: 'keep',
      targets: []
    })
  }

  const validActionTypes = ['week', 'custom', 'cancel']
  const normalizedActionType = validActionTypes.includes(actionType)
    ? actionType
    : 'week'

  let description = ''
  let actionsHtml = ''

  if (normalizedActionType === 'cancel') {
    description = `
      Tu annules une première diffusion.
      Choisis ce qu’il faut faire avec ses rediffusions.
    `

    actionsHtml = `
      <button type="button" data-choice="keep">
        Conserver les rediffusions prévues
      </button>

      <button type="button" data-choice="cancel">
        Annuler aussi les rediffusions
      </button>
    `
  } else if (normalizedActionType === 'custom') {
    description = `
      Tu déplaces une première diffusion.
      Choisis ce qu’il faut faire avec ses rediffusions.
    `

    actionsHtml = `
      <button type="button" data-choice="keep">
        Laisser les rediffusions à leurs horaires actuels
      </button>

      <button type="button" data-choice="move">
        Appliquer le même décalage aux rediffusions
      </button>

      <button type="button" data-choice="custom">
        Choisir individuellement leurs nouveaux horaires
      </button>

      <button type="button" data-choice="cancel">
        Annuler les rediffusions liées
      </button>
    `
  } else {
    description = `
      Tu déplaces une première diffusion d’une semaine.
      Choisis ce qu’il faut faire avec ses rediffusions.
    `

    actionsHtml = `
      <button type="button" data-choice="keep">
        Laisser les rediffusions à leurs horaires actuels
      </button>

      <button type="button" data-choice="move">
        Déplacer aussi les rediffusions d’une semaine
      </button>

      <button type="button" data-choice="cancel">
        Annuler les rediffusions liées
      </button>
    `
  }

  return new Promise((resolve) => {
    const overlay = document.createElement('div')
    overlay.className = 'rebroadcast-modal-overlay'

    overlay.innerHTML = `
      <div
        class="rebroadcast-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rebroadcast-modal-title"
      >
        <h3 id="rebroadcast-modal-title">
          Que faire des rediffusions liées ?
        </h3>

        <p>
          ${description}
        </p>

        <div class="rebroadcast-modal__actions">
          ${actionsHtml}
        </div>

        <div
          class="rebroadcast-custom-zone"
          style="display:none;"
        ></div>

        <button
          type="button"
          class="rebroadcast-modal__close"
          data-choice="dismiss"
        >
          Retour
        </button>
      </div>
    `

    const customZone = overlay.querySelector(
      '.rebroadcast-custom-zone'
    )

    function cleanup(result = {
      strategy: 'dismiss',
      targets: []
    }) {
      document.removeEventListener(
        'keydown',
        onEscape
      )

      overlay.remove()
      resolve(result)
    }

    function onEscape(event) {
      if (event.key !== 'Escape') {
        return
      }

      cleanup()
    }

    async function loadCustomTargets() {
      const slotId =
        context.selectedPostit.dataset.slotId || ''

      const startsAt =
        context.selectedPostit.dataset.originalStartsAt ||
        context.selectedPostit.dataset.startsAt ||
        ''

      if (!slotId || !startsAt) {
        cleanup({
          strategy: 'dismiss',
          targets: []
        })
        return
      }

      customZone.style.display = 'block'
      customZone.innerHTML =
        '<div>Chargement des rediffusions…</div>'

      try {
        const params = new URLSearchParams({
          slotId,
          startsAt
        })

        const data = await getJson(
          `/admin/grille/linked-rebroadcasts?${params.toString()}`
        )

        const items = Array.isArray(data.items)
          ? data.items
          : []

        if (!items.length) {
          customZone.innerHTML =
            '<div>Aucune rediffusion liée trouvée.</div>'
          return
        }

        customZone.innerHTML = items.map((item) => `
          <div class="rebroadcast-item">
            <div class="rebroadcast-item__label">
              ${escapeHtml(
                item.label || 'Rediffusion'
              )}
            </div>

            <div class="rebroadcast-item__meta">
              Actuellement :
              ${escapeHtml(item.startsAt || '')}
            </div>

            <input
              type="date"
              data-rebroadcast-date
              data-slot-id="${escapeHtml(item.slotId)}"
              value="${escapeHtml(
                (item.startsAt || '').slice(0, 10)
              )}"
            >

            <input
              type="time"
              step="900"
              data-rebroadcast-time
              value="${escapeHtml(
                (item.startsAt || '').slice(11, 16)
              )}"
            >
          </div>
        `).join('')

        const confirmButton =
          document.createElement('button')

        confirmButton.type = 'button'
        confirmButton.className =
          'rebroadcast-modal__confirm'

        confirmButton.textContent =
          'Valider ces horaires'

        confirmButton.addEventListener(
          'click',
          () => {
            const targets = []

            customZone
              .querySelectorAll(
                '.rebroadcast-item'
              )
              .forEach((row) => {
                const dateInput =
                  row.querySelector(
                    '[data-rebroadcast-date]'
                  )

                const timeInput =
                  row.querySelector(
                    '[data-rebroadcast-time]'
                  )

                targets.push({
                  slotId:
                    dateInput?.dataset.slotId || '',
                  newDate:
                    dateInput?.value || '',
                  newTime:
                    timeInput?.value || ''
                })
              })

            cleanup({
              strategy: 'custom',
              targets
            })
          }
        )

        customZone.appendChild(confirmButton)
      } catch (error) {
        customZone.innerHTML = `
          <div>
            Impossible de charger les rediffusions.
          </div>
        `
      }
    }

    document.body.appendChild(overlay)

    overlay
      .querySelectorAll('[data-choice]')
      .forEach((button) => {
        button.addEventListener(
          'click',
          async () => {
            const choice =
              button.dataset.choice || 'dismiss'

            if (choice === 'dismiss') {
              cleanup()
              return
            }

            if (choice === 'custom') {
              await loadCustomTargets()
              return
            }

            cleanup({
              strategy: choice,
              targets: []
            })
          }
        )
      })

    overlay.addEventListener(
      'click',
      (event) => {
        if (event.target === overlay) {
          cleanup()
        }
      }
    )

    document.addEventListener(
      'keydown',
      onEscape
    )
  })
}

export function askRestoreRebroadcastStrategy(context) {
    if (!context.selectedPostit) {
        return Promise.resolve({
            restoreLinkedRebroadcasts: false,
            dismissed: false
        })
    }

    return new Promise((resolve) => {
        const overlay = document.createElement('div')
        overlay.className = 'rebroadcast-modal-overlay'

        overlay.innerHTML = `
            <div
                class="rebroadcast-modal"
                role="dialog"
                aria-modal="true"
            >
                <h3>Restaurer les rediffusions ?</h3>

                <p>
                    Tu restaures ce créneau. Veux-tu restaurer
                    aussi les rediffusions associées ?
                </p>

                <div class="rebroadcast-modal__actions">
                    <button
                        type="button"
                        data-choice="only-current"
                    >
                        Restaurer uniquement ce créneau
                    </button>

                    <button
                        type="button"
                        data-choice="with-linked"
                    >
                        Restaurer aussi les rediffusions
                    </button>

                    <button
                        type="button"
                        data-choice="dismiss"
                    >
                        Retour
                    </button>
                </div>
            </div>
        `

        let resolved = false

        const onKeyDown = (event) => {
            if (event.key !== 'Escape') {
                return
            }

            dismiss()
        }

        const cleanup = (result) => {
            if (resolved) {
                return
            }

            resolved = true

            document.removeEventListener(
                'keydown',
                onKeyDown
            )

            overlay.remove()

            resolve(result)
        }

        const dismiss = () => {
            cleanup({
                restoreLinkedRebroadcasts: false,
                dismissed: true
            })
        }

        document.body.appendChild(overlay)

        document.addEventListener(
            'keydown',
            onKeyDown
        )

        overlay
            .querySelector(
                '[data-choice="only-current"]'
            )
            .addEventListener('click', () => {
                cleanup({
                    restoreLinkedRebroadcasts: false,
                    dismissed: false
                })
            })

        overlay
            .querySelector(
                '[data-choice="with-linked"]'
            )
            .addEventListener('click', () => {
                cleanup({
                    restoreLinkedRebroadcasts: true,
                    dismissed: false
                })
            })

        overlay
            .querySelector(
                '[data-choice="dismiss"]'
            )
            .addEventListener(
                'click',
                dismiss
            )

        overlay.addEventListener(
            'click',
            (event) => {
                if (event.target !== overlay) {
                    return
                }

                dismiss()
            }
        )
    })
}
