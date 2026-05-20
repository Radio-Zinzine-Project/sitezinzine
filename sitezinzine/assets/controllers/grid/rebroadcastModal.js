import { escapeHtml } from './utils'

export function askRebroadcastStrategy(
  context,
  actionLabel = 'modifier'
) {
  if (!context.selectedPostit) {
    return Promise.resolve('keep')
  }

  const broadcastRank = parseInt(
    context.selectedPostit.dataset.broadcastRank || '1',
    10
  )

  if (broadcastRank > 1) {
    return Promise.resolve('keep')
  }

  return new Promise((resolve) => {
    const overlay = document.createElement('div')

    overlay.className =
      'rebroadcast-modal-overlay'

    overlay.innerHTML = `
      <div
        class="rebroadcast-modal"
        role="dialog"
        aria-modal="true"
      >
        <h3>
          Que faire des rediffusions liées ?
        </h3>

        <p>
          Tu modifies une première diffusion.
          Choisis ce qu’il faut faire avec
          ses rediffusions.
        </p>

        <div class="rebroadcast-modal__actions">

          <button
            type="button"
            data-choice="keep"
          >
            Garder les rediffs
          </button>

          <button
            type="button"
            data-choice="cancel"
          >
            Annuler les rediffs
          </button>

          <button
            type="button"
            data-choice="move"
          >
            Les ${escapeHtml(actionLabel)} aussi
          </button>

        </div>

        <button
          type="button"
          class="rebroadcast-modal__close"
          data-choice="keep"
        >
          Fermer
        </button>

      </div>
    `

    function cleanup(choice='keep') {
      document.removeEventListener(
        'keydown',
        onEscape
      )

      overlay.remove()

      resolve(choice)
    }

    function onEscape(event) {
      if (event.key !== 'Escape') {
        return
      }

      cleanup()
    }

    document.body.appendChild(overlay)

    overlay
      .querySelectorAll('[data-choice]')
      .forEach((button)=>{

        button.addEventListener(
          'click',
          ()=>cleanup(
            button.dataset.choice || 'keep'
          )
        )

      })

    overlay.addEventListener(
      'click',
      (event)=>{

        if(event.target===overlay){
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
