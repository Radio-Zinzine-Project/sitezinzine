import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = [
    'day',
    'emptyState',
    'sidebarPanel',
    'slotSummary',
    'emissionsList',
    'slotActions',
    'arbitrationActions',
    'modeRegularBtn',
    'modeSpecialBtn',
    'regularPanel',
    'regularSearch',
    'regularShowAllBtn',
    'regularOtherCategoryBtn',
    'regularCategorySelect',
    'specialPanel',
    'specialCategorySelect',
    'specialShowAllBtn',
    'specialStatus',
    'specialEmptyState',
    'specialSidebarPanel',
    'specialSlotSummary',
    'trashZone'
  ]

  connect() {
    this.CELL_MIN = 15
    this.CELL_H = this.getSlotHeight()

    this.dragged = null
    this.fromDay = null
    this.fromStartIndex = null
    this.selectedPostit = null
    this.currentMode = 'regular'
    this.regularExtended = false
    this.regularSearchTimeout = null
    this.regularOtherCategory = false

    this.element.querySelectorAll('.postit').forEach((el) => {
      this.makeDraggable(el, 'grid')
    })

    this.dayTargets.forEach((day) => {
      day.addEventListener('dragover', (e) => {
        e.preventDefault()
        day.classList.add('drag-over')
      })

      day.addEventListener('dragleave', () => {
        day.classList.remove('drag-over')
      })

      day.addEventListener('drop', (e) => this.dropOnDay(e, day))
    })

    const regularPool = this.element.querySelector('#emissions-pool')
    if (regularPool) {
      regularPool.addEventListener('dragover', (e) => {
        e.preventDefault()
        regularPool.classList.add('drop-pool-hover')
      })

      regularPool.addEventListener('dragleave', () => {
        regularPool.classList.remove('drop-pool-hover')
      })

      regularPool.addEventListener('drop', (e) => this.dropBackToPool(e, regularPool))
    }

    const specialPool = this.element.querySelector('#emissions-pool-special')
    if (specialPool) {
      specialPool.addEventListener('dragover', (e) => {
        e.preventDefault()
        specialPool.classList.add('drop-pool-hover')
      })

      specialPool.addEventListener('dragleave', () => {
        specialPool.classList.remove('drop-pool-hover')
      })

      specialPool.addEventListener('drop', (e) => this.dropBackToPool(e, specialPool))
    }

    if (this.hasTrashZoneTarget) {
      this.trashZoneTarget.addEventListener('dragover', (e) => {
        if (!this.canDropInTrash()) {
          return
        }

        e.preventDefault()
        this.trashZoneTarget.classList.add('is-active')
      })

      this.trashZoneTarget.addEventListener('dragleave', () => {
        this.trashZoneTarget.classList.remove('is-active')
      })

      this.trashZoneTarget.addEventListener('drop', (e) => this.dropOnTrash(e))
    }

    const savedMode = this.getSavedMode()

    if (savedMode === 'special') {
      this.showSpecialMode()
    } else {
      this.showRegularMode()
    }
  }

  regularSearch() {
    clearTimeout(this.regularSearchTimeout)

    this.regularSearchTimeout = setTimeout(() => {
      this.loadCandidatesForSelectedPostit()
    }, 300)
  }

  async toggleRegularExtended() {
    this.regularExtended = !this.regularExtended

    if (this.hasRegularShowAllBtnTarget) {
      this.regularShowAllBtnTarget.classList.toggle('is-active', this.regularExtended)
      this.regularShowAllBtnTarget.textContent = this.regularExtended
        ? 'Revenir aux suggestions récentes'
        : 'Afficher plus'
    }

    await this.loadCandidatesForSelectedPostit()
  }

  getSlotHeight() {
    const rootStyles = getComputedStyle(document.documentElement)
    const rawValue = rootStyles.getPropertyValue('--slot-h').trim()

    if (!rawValue) {
      return 14
    }

    const parsed = parseFloat(rawValue.replace('px', ''))
    return Number.isNaN(parsed) ? 14 : parsed
  }

  getWeekStart() {
    return this.element.dataset.gridWeekStart || ''
  }

  getCurrentEmissionsListTarget() {
    const targets = this.emissionsListTargets || []

    if (this.currentMode === 'special') {
      return targets[1] || targets[0] || null
    }

    return targets[0] || null
  }

  setEmissionsListHtml(html) {
    const emissionsList = this.getCurrentEmissionsListTarget()

    if (!emissionsList) {
      return
    }

    emissionsList.innerHTML = html
  }

  showRegularMode() {
    this.currentMode = 'regular'
    this.saveCurrentMode()

    this.regularPanelTarget.style.display = 'block'
    this.specialPanelTarget.style.display = 'none'

    this.modeRegularBtnTarget.classList.add('is-active')
    this.modeSpecialBtnTarget.classList.remove('is-active')

    this.modeRegularBtnTarget.setAttribute('aria-pressed', 'true')
    this.modeSpecialBtnTarget.setAttribute('aria-pressed', 'false')

    if (this.hasTrashZoneTarget) {
      this.trashZoneTarget.classList.remove('is-active')
    }
  }

  showSpecialMode() {
    this.currentMode = 'special'
    this.saveCurrentMode()

    this.regularPanelTarget.style.display = 'none'
    this.specialPanelTarget.style.display = 'block'

    this.modeRegularBtnTarget.classList.remove('is-active')
    this.modeSpecialBtnTarget.classList.add('is-active')

    this.modeRegularBtnTarget.setAttribute('aria-pressed', 'false')
    this.modeSpecialBtnTarget.setAttribute('aria-pressed', 'true')

    this.specialShowAllBtnTarget.style.display = 'none'
    this.specialStatusTarget.textContent = 'Sélectionne une catégorie pour charger les émissions.'
    this.specialEmptyStateTarget.style.display = 'block'
    this.specialSidebarPanelTarget.style.display = 'none'
    this.setEmissionsListHtml('')
  }

  durationToCells(duration) {
    const value = parseInt(duration || '15', 10)
    return Math.max(1, Math.ceil(value / this.CELL_MIN))
  }

  durationToPx(duration) {
    const value = parseInt(duration || '15', 10)
    return Math.max(12, (value / this.CELL_MIN) * this.CELL_H - 2)
  }

  makeDraggable(el, source) {
    const isLocked = el.dataset.slotLocked === 'true'
    const isNonBlocking = el.dataset.isBlocking === 'false'
    const canRestore = el.dataset.canRestore === 'true'

    if (isLocked || isNonBlocking || canRestore) {
      el.setAttribute('draggable', 'false')
      el.dataset.source = source
      return
    }

    el.setAttribute('draggable', 'true')
    el.dataset.source = source

    el.addEventListener('dragstart', () => {
      this.dragged = el

      if (source === 'grid') {
        this.fromDay = el.closest('.day-col')
        const top = parseFloat(el.style.top || '0')
        this.fromStartIndex = Math.round(top / this.CELL_H)
      } else {
        this.fromDay = null
        this.fromStartIndex = null
      }
    })

    el.addEventListener('dragend', () => {
      this.dayTargets.forEach((day) => day.classList.remove('drag-over'))

      const regularPool = this.element.querySelector('#emissions-pool')
      const specialPool = this.element.querySelector('#emissions-pool-special')

      if (regularPool) {
        regularPool.classList.remove('drop-pool-hover')
      }

      if (specialPool) {
        specialPool.classList.remove('drop-pool-hover')
      }

      if (this.hasTrashZoneTarget) {
        this.trashZoneTarget.classList.remove('is-active')
      }

      this.dragged = null
      this.fromDay = null
      this.fromStartIndex = null
    })
  }

  canDropInTrash() {
    return !!(
      this.currentMode === 'special' &&
      this.dragged &&
      this.dragged.dataset.source === 'grid' &&
      this.dragged.dataset.isManualDraft === 'true'
    )
  }

  async dropOnTrash(event) {
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

    const confirmed = window.confirm('Supprimer cette programmation ponctuelle ?')
    if (!confirmed) {
      return
    }

    try {
      const response = await fetch('/admin/grid-drafts/delete', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ draftId })
      })

      const data = await response.json().catch(() => null)

      if (!response.ok || !data?.success) {
        throw new Error(data?.error || 'Impossible de supprimer ce draft.')
      }

      this.saveCurrentMode()
      window.location.reload()
    } catch (error) {
      alert(error.message || 'Erreur lors de la suppression du draft.')
    }
  }

  escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;')
  }

  getStatusLabel(postit) {
    const isCancelled = postit.dataset.isCancelled === 'true'
    const isRescheduledOrigin = postit.dataset.isRescheduledOrigin === 'true'
    const isLive = postit.dataset.emissionIsAutoGenerated === 'true'
    const isProjectedOverride = postit.dataset.isProjectedOverride === 'true'
    const isManualDraft = postit.dataset.isManualDraft === 'true'
    const broadcastRank = parseInt(postit.dataset.broadcastRank || '1', 10)

    if (isCancelled) {
      return 'Créneau annulé'
    }

    if (isRescheduledOrigin) {
      return 'Ancien emplacement déplacé'
    }

    if (isManualDraft) {
      return isLive ? 'Direct ponctuel' : 'Programmation ponctuelle'
    }

    if (broadcastRank > 1) {
      return `Rediffusion ${broadcastRank - 1}`
    }

    if (isProjectedOverride) {
      return 'Occurrence déplacée'
    }

    if (isLive) {
      return 'Direct'
    }

    return '1re diffusion'
  }

  applyPostitVariant(postit) {
    const isLive = postit.dataset.emissionIsAutoGenerated === 'true'
    const isProjectedOverride = postit.dataset.isProjectedOverride === 'true'
    const isManualDraft = postit.dataset.isManualDraft === 'true'
    const broadcastRank = parseInt(postit.dataset.broadcastRank || '1', 10)

    postit.classList.remove(
      'postit--first',
      'postit--live',
      'postit--rebroadcast',
      'postit--override',
      'postit--rescheduled'
    )

    if (isManualDraft) {
      postit.classList.add(isLive ? 'postit--live' : 'postit--override')
      return
    }

    if (broadcastRank > 1) {
      postit.classList.add('postit--rebroadcast')
      return
    }

    if (isProjectedOverride) {
      postit.classList.add('postit--rescheduled')
      return
    }

    if (isLive) {
      postit.classList.add('postit--live')
      return
    }

    postit.classList.add('postit--first')
  }

  applyConflictState(postit) {
    const hasConflict = postit.dataset.hasConflict === 'true'
    postit.classList.toggle('postit--conflict', hasConflict)

    if (hasConflict) {
      const severity = postit.dataset.conflictSeverity || ''
      if (severity) {
        postit.dataset.conflictSeverity = severity
      }
    }
  }

  getConflictSeverityLabel(postit) {
    const severity = postit.dataset.conflictSeverity || ''

    switch (severity) {
      case 'total':
        return 'Conflit total'
      case 'contained':
        return 'Conflit inclus'
      case 'partial':
        return 'Conflit partiel'
      default:
        return 'Conflit'
    }
  }

  getConflictTypeLabel(postit) {
    const conflictType = postit.dataset.conflictType || ''

    switch (conflictType) {
      case 'same_slot_overlap':
        return 'Chevauchement sur le même slot'
      case 'same_rule_overlap':
        return 'Chevauchement dans la même règle'
      case 'rule_overlap':
        return 'Chevauchement entre règles'
      case 'multiple':
        return 'Conflits multiples'
      default:
        return 'Conflit détecté'
    }
  }

  parseConflictWith(postit) {
    const raw = postit.dataset.conflictWith || '[]'

    try {
      const parsed = JSON.parse(raw)
      return Array.isArray(parsed) ? parsed : []
    } catch {
      return []
    }
  }

  buildConflictSummary(postit) {
    const hasConflict = postit.dataset.hasConflict === 'true'
    if (!hasConflict) {
      return ''
    }

    const conflictCount = parseInt(postit.dataset.conflictCount || '0', 10)
    const severityLabel = this.getConflictSeverityLabel(postit)
    const typeLabel = this.getConflictTypeLabel(postit)
    const conflicts = this.parseConflictWith(postit)

    let html = `
      <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ececec;">
        <div><span class="label">Conflit :</span> ${this.escapeHtml(typeLabel)}</div>
        <div><span class="label">Niveau :</span> ${this.escapeHtml(severityLabel)}</div>
        <div><span class="label">Nombre :</span> ${this.escapeHtml(conflictCount)}</div>
    `

    if (conflicts.length > 0) {
      html += '<div style="margin-top:8px;"><span class="label">Créneaux concernés :</span></div>'
      html += '<ul style="margin:6px 0 0 18px; padding:0;">'

      conflicts.forEach((item) => {
        const categoryTitle = item.categoryTitle || 'Catégorie inconnue'
        const startsAt = item.startsAt || ''
        const endsAt = item.endsAt || ''
        const ruleDisplayName = item.ruleDisplayName || ''
        const broadcastRank = parseInt(item.broadcastRank || '1', 10)
        const typeLabelItem = broadcastRank > 1
          ? `Rediffusion ${broadcastRank - 1}`
          : '1re diffusion'

        html += `
          <li style="margin-bottom:6px;">
            <div>${this.escapeHtml(categoryTitle)}</div>
            ${ruleDisplayName ? `<div style="font-size:12px; color:#555;">${this.escapeHtml(ruleDisplayName)}</div>` : ''}
            <div style="font-size:12px; color:#555;">
              ${this.escapeHtml(startsAt)}${endsAt ? ` → ${this.escapeHtml(endsAt)}` : ''}
            </div>
            <div style="font-size:12px; color:#555;">${this.escapeHtml(typeLabelItem)}</div>
          </li>
        `
      })

      html += '</ul>'
    }

    html += '</div>'

    return html
  }

  buildProjectionSummary(postit) {
    const isProjectedOverride = postit.dataset.isProjectedOverride === 'true'
    if (!isProjectedOverride) {
      return ''
    }

    const originalStartsAt = postit.dataset.originalStartsAt || ''
    const projectionType = postit.dataset.projectionType || ''

    let label = 'Déplacement'
    if (projectionType === 'reschedule_previous_week') {
      label = 'Déplacé depuis la semaine suivante'
    } else if (projectionType === 'reschedule_next_week') {
      label = 'Déplacé depuis la semaine précédente'
    } else if (projectionType === 'reschedule_custom') {
      label = 'Déplacé manuellement'
    }

    return `
      <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ececec;">
        <div><span class="label">Exception locale :</span> ${this.escapeHtml(label)}</div>
        ${originalStartsAt ? `<div><span class="label">Créneau d’origine :</span> ${this.escapeHtml(originalStartsAt)}</div>` : ''}
      </div>
    `
  }

  buildSlotSummary(postit, assignedEmissionTitle = '') {
    const isManualDraft = postit.dataset.isManualDraft === 'true'
    const isCancelled = postit.dataset.isCancelled === 'true'
    const isRescheduledOrigin = postit.dataset.isRescheduledOrigin === 'true'
    const canRestore = postit.dataset.canRestore === 'true'

    const categoryTitle = postit.dataset.categoryTitle || 'Catégorie inconnue'
    const ruleDisplayName = postit.dataset.ruleDisplayName || categoryTitle
    const startsAt = postit.dataset.startsAt || ''
    const endsAt = postit.dataset.endsAt || ''
    const safeAssignedTitle = assignedEmissionTitle || postit.dataset.assignedEmissionTitle || ''
    const statusLabel = this.getStatusLabel(postit)

    if (isManualDraft) {
      return `
      <div><span class="label">Type :</span> ${this.escapeHtml(statusLabel)}</div>
      <div><span class="label">Catégorie :</span> ${this.escapeHtml(categoryTitle)}</div>
      <div><span class="label">Début :</span> ${this.escapeHtml(startsAt)}</div>
      ${endsAt ? `<div><span class="label">Fin :</span> ${this.escapeHtml(endsAt)}</div>` : ''}
      ${safeAssignedTitle
          ? `<div><span class="label">Émission :</span> ${this.escapeHtml(safeAssignedTitle)}</div>`
          : ''
        }
    `
    }

    if (isCancelled || isRescheduledOrigin) {
      return `
      <div><span class="label">Statut :</span> ${this.escapeHtml(statusLabel)}</div>
      <div><span class="label">Règle :</span> ${this.escapeHtml(ruleDisplayName)}</div>
      <div><span class="label">Catégorie :</span> ${this.escapeHtml(categoryTitle)}</div>
      <div><span class="label">Début d’origine :</span> ${this.escapeHtml(startsAt)}</div>
      ${endsAt ? `<div><span class="label">Fin d’origine :</span> ${this.escapeHtml(endsAt)}</div>` : ''}
      <div><span class="label">Blocage :</span> non</div>
      ${canRestore ? '<div style="margin-top:8px;">Ce créneau peut être restauré.</div>' : ''}
    `
    }

    return `
    <div><span class="label">Règle :</span> ${this.escapeHtml(ruleDisplayName)}</div>
    <div><span class="label">Catégorie :</span> ${this.escapeHtml(categoryTitle)}</div>
    <div><span class="label">Début :</span> ${this.escapeHtml(startsAt)}</div>
    ${endsAt ? `<div><span class="label">Fin :</span> ${this.escapeHtml(endsAt)}</div>` : ''}
    <div><span class="label">Type :</span> ${this.escapeHtml(statusLabel)}</div>
    ${safeAssignedTitle
        ? `<div><span class="label">Émission affectée :</span> ${this.escapeHtml(safeAssignedTitle)}</div>`
        : ''
      }
    ${this.buildProjectionSummary(postit)}
    ${this.buildConflictSummary(postit)}
  `
  }

  buildSpecialSlotSummary(postit) {
    const startsAt = postit.dataset.startsAt || ''
    const endsAt = postit.dataset.endsAt || ''
    const categoryTitle = postit.dataset.categoryTitle || 'Créneau manuel'
    const title = postit.dataset.assignedEmissionTitle || ''
    const isLive = postit.dataset.emissionIsAutoGenerated === 'true'

    return `
      <div><span class="label">Début :</span> ${this.escapeHtml(startsAt)}</div>
      ${endsAt ? `<div><span class="label">Fin :</span> ${this.escapeHtml(endsAt)}</div>` : ''}
      <div><span class="label">Catégorie :</span> ${this.escapeHtml(categoryTitle)}</div>
      ${title ? `<div><span class="label">Émission :</span> ${this.escapeHtml(title)}</div>` : ''}
      <div><span class="label">Type :</span> ${isLive ? 'Direct ponctuel' : 'Programmation ponctuelle'}</div>
    `
  }

  buildCreateLiveButton() {
    return `
      <div class="create-live-box">
        <button
          type="button"
          class="btn-create-live"
          data-action="click->grid#createLiveEmission"
        >
          Créer un direct
        </button>
      </div>
    `
  }

  buildArbitrationActions(postit) {
    const canRestore = postit.dataset.canRestore === 'true'
    const isCancelled = postit.dataset.isCancelled === 'true'
    const isRescheduledOrigin = postit.dataset.isRescheduledOrigin === 'true'

    if (canRestore || isCancelled || isRescheduledOrigin) {
      return `
      <div class="slot-actions">
        <div class="slot-actions-title">↩ Créneau restaurable</div>
        <div class="slot-actions-help">
          Ce créneau ne bloque pas la grille. Tu peux poser une programmation ponctuelle par-dessus,
          ou restaurer le créneau régulier.
        </div>

        <button
          type="button"
          class="btn-arbitration"
          data-action="click->grid#restoreOccurrence"
        >
          Restaurer ce créneau
        </button>
      </div>
    `
    }

    const canReschedule = postit.dataset.canReschedule === 'true'
    const isProjectedOverride = postit.dataset.isProjectedOverride === 'true'
    const originalStartsAt = postit.dataset.originalStartsAt || ''

    if (!canReschedule) {
      return ''
    }

    if (isProjectedOverride && originalStartsAt) {
      return `
      <div class="slot-actions">
        <div class="slot-actions-title">🎯 Créneau sélectionné</div>
        <div class="slot-actions-help">
          Cette occurrence a déjà été déplacée.
        </div>

        <button
          type="button"
          class="btn-arbitration"
          data-action="click->grid#clearReschedule"
        >
          Revenir au créneau d’origine
        </button>
      </div>
    `
    }

    return `
    <div class="slot-actions">
      <div class="slot-actions-title">🎯 Créneau sélectionné</div>
      <div class="slot-actions-help">
        Ces actions s’appliquent uniquement au créneau actuellement sélectionné.
      </div>

      <button type="button" class="btn-arbitration" data-action="click->grid#reschedulePreviousWeek">
        Décaler à la semaine précédente
      </button>

      <button type="button" class="btn-arbitration" data-action="click->grid#rescheduleNextWeek">
        Décaler à la semaine suivante
      </button>

      <button type="button" class="btn-arbitration" data-action="click->grid#toggleCustomRescheduleForm">
        Choisir une autre date / heure
      </button>

      <div class="reschedule-form" data-grid-custom-reschedule-form style="display:none;">
        <input type="date" data-grid-custom-date>
        <input type="time" data-grid-custom-time step="900">
        <button type="button" class="btn-arbitration" data-action="click->grid#submitCustomReschedule">
          Confirmer le déplacement
        </button>
      </div>

      <button type="button" class="btn-arbitration btn-arbitration--danger" data-action="click->grid#cancelOccurrence">
        Annuler ce créneau
      </button>
    </div>
  `
  }

  buildSelectedOccurrenceItem(postit) {
    return {
      segmentKey: postit.dataset.segmentKey || '',
      slotId: postit.dataset.slotId || '',
      ruleId: postit.dataset.ruleId || '',
      ruleDisplayName: postit.dataset.ruleDisplayName || '',
      categoryTitle: postit.dataset.categoryTitle || 'Catégorie inconnue',
      broadcastRank: parseInt(postit.dataset.broadcastRank || '1', 10),
      startsAt: postit.dataset.startsAt || '',
      endsAt: postit.dataset.endsAt || '',
      isProjectedOverride: postit.dataset.isProjectedOverride === 'true',
      projectionType: postit.dataset.projectionType || '',
      isSelectedOccurrence: true
    }
  }

  buildConflictGroup(postit) {
    const selected = this.buildSelectedOccurrenceItem(postit)
    const conflicts = this.parseConflictWith(postit)

    const all = [selected, ...conflicts]
    const deduped = []

    all.forEach((item) => {
      const key = item.segmentKey || `${item.slotId || ''}|${item.startsAt || ''}`
      const exists = deduped.some((existing) => {
        const existingKey = existing.segmentKey || `${existing.slotId || ''}|${existing.startsAt || ''}`
        return existingKey === key
      })

      if (!exists) {
        deduped.push(item)
      }
    })

    return deduped
  }

  buildOccurrenceTypeLabel(item) {
    const broadcastRank = parseInt(item.broadcastRank || '1', 10)
    const isProjectedOverride = item.isProjectedOverride === true

    if (isProjectedOverride) {
      return 'Occurrence déplacée'
    }

    if (broadcastRank > 1) {
      return `Rediffusion ${broadcastRank - 1}`
    }

    return '1re diffusion'
  }

  buildConflictArbitrationUI(postit) {
    const hasConflict = postit.dataset.hasConflict === 'true'

    if (!hasConflict) {
      return ''
    }

    const occurrences = this.buildConflictGroup(postit)

    if (!occurrences.length) {
      return ''
    }

    let html = `
      <div class="conflict-section">
        <div class="conflict-title">⚠ Résolution du conflit</div>
        <div class="conflict-help">
          Choisis directement une action sur le créneau que tu veux modifier.
        </div>
    `

    let otherConflictIndex = 0

    occurrences.forEach((item) => {
      const categoryTitle = item.categoryTitle || 'Catégorie inconnue'
      const startsAt = item.startsAt || ''
      const endsAt = item.endsAt || ''
      const ruleDisplayName = item.ruleDisplayName || ''
      const typeLabel = this.buildOccurrenceTypeLabel(item)
      const badge = item.isSelectedOccurrence
        ? '<span class="conflict-card__badge">Sélectionné</span>'
        : '<span class="conflict-card__badge conflict-card__badge--other">En conflit</span>'

      let actionsHtml = ''

      if (item.isSelectedOccurrence) {
        actionsHtml = `
          <button type="button" class="btn-arbitration" data-action="click->grid#reschedulePreviousWeek">
            Décaler à la semaine précédente
          </button>

          <button type="button" class="btn-arbitration" data-action="click->grid#rescheduleNextWeek">
            Décaler à la semaine suivante
          </button>

          <button type="button" class="btn-arbitration" data-action="click->grid#toggleCustomRescheduleForm">
            Choisir une autre date / heure
          </button>

          <div class="reschedule-form" data-grid-custom-reschedule-form style="display:none;">
            <input type="date" data-grid-custom-date>
            <input type="time" data-grid-custom-time step="900">
            <button type="button" class="btn-arbitration" data-action="click->grid#submitCustomReschedule">
              Confirmer le déplacement
            </button>
          </div>

          <button type="button" class="btn-arbitration btn-arbitration--danger" data-action="click->grid#cancelOccurrence">
            Annuler ce créneau
          </button>
        `
      } else {
        actionsHtml = `
          <button type="button" class="btn-arbitration" data-action="click->grid#arbitratePreviousWeek" data-conflict-index="${otherConflictIndex}">
            Décaler -1 semaine
          </button>

          <button type="button" class="btn-arbitration" data-action="click->grid#arbitrateNextWeek" data-conflict-index="${otherConflictIndex}">
            Décaler +1 semaine
          </button>

          <button type="button" class="btn-arbitration btn-arbitration--danger" data-action="click->grid#arbitrateCancel" data-conflict-index="${otherConflictIndex}">
            Annuler ce créneau
          </button>
        `
        otherConflictIndex++
      }

      html += `
        <div class="conflict-card ${item.isSelectedOccurrence ? 'conflict-card--selected' : ''}">
          <div class="conflict-card__header">
            <div class="conflict-card__title">${this.escapeHtml(categoryTitle)}</div>
            ${badge}
          </div>

          ${ruleDisplayName ? `<div class="conflict-card__meta">${this.escapeHtml(ruleDisplayName)}</div>` : ''}

          <div class="conflict-card__meta">
            ${this.escapeHtml(startsAt)}${endsAt ? ` → ${this.escapeHtml(endsAt)}` : ''}
          </div>

          <div class="conflict-card__meta">
            ${this.escapeHtml(typeLabel)}
          </div>

          <div class="conflict-card__actions">
            ${actionsHtml}
          </div>
        </div>
      `
    })

    html += '</div>'

    return html
  }

  getPostitLabels(postit, title = '') {
    const slots = parseInt(postit.dataset.slots || '1', 10)
    const categoryTitle = postit.dataset.categoryTitle || 'Catégorie inconnue'
    const categorySlug = postit.dataset.categorySlug || ''
    const safeTitle = title || ''

    if (slots === 1) {
      if (safeTitle) {
        return {
          mainLabel: `${categorySlug || categoryTitle} — ${safeTitle}`,
          secondaryLabel: ''
        }
      }

      return {
        mainLabel: categoryTitle,
        secondaryLabel: ''
      }
    }

    return {
      mainLabel: categoryTitle,
      secondaryLabel: safeTitle
    }
  }

  getBadgeText(postit) {
    const isLive = postit.dataset.emissionIsAutoGenerated === 'true'
    const isProjectedOverride = postit.dataset.isProjectedOverride === 'true'
    const isManualDraft = postit.dataset.isManualDraft === 'true'
    const broadcastRank = parseInt(postit.dataset.broadcastRank || '1', 10)

    if (isManualDraft) {
      return isLive ? '● Direct' : ''
    }

    if (broadcastRank > 1) {
      return `↺ Rediff ${broadcastRank - 1}`
    }

    if (isProjectedOverride) {
      return '↔ Déplacé'
    }

    if (isLive) {
      return '● Direct'
    }

    return '■ 1re diff'
  }

  updatePostitTooltip(postit, assignedTitle = '') {
    const categoryTitle = postit.dataset.categoryTitle || 'Catégorie inconnue'
    const emissionTitle = assignedTitle || postit.dataset.assignedEmissionTitle || ''
    const statusLabel = this.getStatusLabel(postit)
    const hasConflict = postit.dataset.hasConflict === 'true'
    const conflictCount = parseInt(postit.dataset.conflictCount || '0', 10)

    let title = categoryTitle

    if (emissionTitle) {
      title += ` | ${emissionTitle}`
    }

    title += ` | ${statusLabel}`

    if (hasConflict) {
      title += ` | ⚠ ${this.getConflictSeverityLabel(postit)}`
      if (conflictCount > 0) {
        title += ` (${conflictCount})`
      }
    }

    postit.setAttribute('title', title)
  }

  renderPostitContent(postit, title = '') {
    this.applyPostitVariant(postit)
    this.applyConflictState(postit)

    const slots = parseInt(postit.dataset.slots || '1', 10)
    const badge = this.getBadgeText(postit)
    const { mainLabel, secondaryLabel } = this.getPostitLabels(postit, title)
    const hasConflict = postit.dataset.hasConflict === 'true'

    postit.innerHTML = `
      <div class="postit__content">
        <span class="postit__label">
          ${this.escapeHtml(mainLabel)}
        </span>

        ${secondaryLabel
        ? `<span class="postit__subline">${this.escapeHtml(secondaryLabel)}</span>`
        : ''
      }

        ${slots > 2 && hasConflict
        ? `<span class="postit__alert">⚠ Conflit</span>`
        : ''
      }

        ${slots > 2 && badge
        ? `<span class="postit__badge">${this.escapeHtml(badge)}</span>`
        : ''
      }
      </div>
    `

    this.updatePostitTooltip(postit, title)
  }

  placePostIt(dayEl, startIndex) {
    if (!this.dragged || this.dragged.dataset.slotLocked === 'true') {
      return
    }

    const duration = parseInt(this.dragged.dataset.duration || '15', 10)
    const heightPx = this.durationToPx(duration)
    const cells = this.durationToCells(duration)

    if (startIndex + cells > 96) {
      startIndex = 96 - cells
    }

    if (startIndex < 0) {
      startIndex = 0
    }

    if (this.dragged.dataset.source === 'pool') {
      dayEl.appendChild(this.dragged)
      this.dragged.dataset.source = 'grid'
    }

    this.dragged.classList.add('postit')
    this.dragged.style.top = `${startIndex * this.CELL_H}px`
    this.dragged.style.left = '3px'
    this.dragged.style.right = '3px'
    this.dragged.style.height = `${heightPx}px`

    this.fromDay = dayEl
    this.fromStartIndex = startIndex
  }

  getStartsAtFromDrop(dayEl, startIndex) {
    const weekStart = this.getWeekStart()
    const dayIndex = parseInt(dayEl.dataset.dayIndex || '0', 10)
    const minutesFromMidnight = startIndex * this.CELL_MIN
    const hours = Math.floor(minutesFromMidnight / 60)
    const minutes = minutesFromMidnight % 60

    const baseDate = new Date(`${weekStart}T00:00:00`)
    baseDate.setDate(baseDate.getDate() + dayIndex)
    baseDate.setHours(hours, minutes, 0, 0)

    const yyyy = baseDate.getFullYear()
    const mm = String(baseDate.getMonth() + 1).padStart(2, '0')
    const dd = String(baseDate.getDate()).padStart(2, '0')
    const hh = String(baseDate.getHours()).padStart(2, '0')
    const mi = String(baseDate.getMinutes()).padStart(2, '0')

    return `${yyyy}-${mm}-${dd} ${hh}:${mi}:00`
  }

  async createSpecialDraftFromDrop(dayEl, startIndex) {
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
      let response
      let data

      if (itemType === 'manual_live') {
        const categoryId = this.dragged.dataset.categoryId || ''

        if (!categoryId) {
          throw new Error('Choisis d’abord une catégorie pour créer un direct.')
        }

        response = await fetch('/admin/grid-drafts/manual-live', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: new URLSearchParams({
            categoryId,
            startsAt
          })
        })

        data = await response.json().catch(() => null)
      } else {
        const emissionId = this.dragged.dataset.emissionId || ''
        const durationMinutes = this.dragged.dataset.emissionDuration || ''

        if (!emissionId) {
          throw new Error('Émission invalide.')
        }

        response = await fetch('/admin/grid-drafts/manual', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: new URLSearchParams({
            emissionId,
            startsAt,
            durationMinutes: String(durationMinutes || ''),
            draftType: 'manual_special'
          })
        })

        data = await response.json().catch(() => null)
      }

      if (response.status === 409) {
        throw new Error(data?.error || 'Conflit détecté sur ce créneau.')
      }

      if (!response.ok || !data?.success) {
        throw new Error(data?.error || 'Impossible de créer la programmation.')
      }

      window.location.reload()
    } catch (error) {
      alert(error.message || 'Impossible de créer la programmation.')
    }
  }

  async moveManualDraftFromDrop(dayEl, startIndex) {
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
      const response = await fetch('/admin/grid-drafts/move', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          draftId,
          startsAt
        })
      })

      const data = await response.json().catch(() => null)

      if (response.status === 409) {
        throw new Error(data?.error || 'Conflit détecté sur ce créneau.')
      }

      if (!response.ok || !data?.success) {
        throw new Error(data?.error || 'Impossible de déplacer ce draft.')
      }

      this.saveCurrentMode()
      window.location.reload()
    } catch (error) {
      alert(error.message || 'Erreur lors du déplacement du draft.')
    }
  }

  dropOnDay(e, dayEl) {
    dayEl.classList.remove('drag-over')

    if (!this.dragged || this.dragged.dataset.slotLocked === 'true') {
      return
    }

    const rect = dayEl.getBoundingClientRect()
    const startIndex = Math.floor((e.clientY - rect.top) / this.CELL_H)

    if (this.dragged.dataset.source === 'pool' && this.dragged.dataset.specialItemType) {
      e.preventDefault()
      this.createSpecialDraftFromDrop(dayEl, startIndex)
      return
    }

    if (
      this.dragged.dataset.source === 'grid' &&
      this.dragged.dataset.isManualDraft === 'true'
    ) {
      e.preventDefault()
      this.moveManualDraftFromDrop(dayEl, startIndex)
      return
    }

    this.placePostIt(dayEl, startIndex)
  }

  dropBackToPool(e, pool) {
    e.preventDefault()
    pool.classList.remove('drop-pool-hover')

    if (!this.dragged || this.dragged.dataset.source !== 'grid' || this.dragged.dataset.slotLocked === 'true') {
      return
    }

    this.dragged.removeAttribute('style')
    this.dragged.classList.remove('postit')
    this.dragged.dataset.source = 'pool'
    pool.appendChild(this.dragged)

    this.dragged = null
    this.fromDay = null
    this.fromStartIndex = null
  }

  async selectSlot(event) {
    const postit = event.currentTarget
    const isLocked = postit.dataset.slotLocked === 'true'
    const isManualDraft = postit.dataset.isManualDraft === 'true'
    const isGhost = postit.dataset.canRestore === 'true' || postit.dataset.isBlocking === 'false'

    if (this.selectedPostit) {
      this.selectedPostit.classList.remove('is-selected')
    }

    this.selectedPostit = postit
    this.selectedPostit.classList.add('is-selected')

    if (this.currentMode === 'special' || isManualDraft) {
      this.specialEmptyStateTarget.style.display = 'none'
      this.specialSidebarPanelTarget.style.display = 'block'
      this.specialSlotSummaryTarget.innerHTML = this.buildSpecialSlotSummary(postit)
      return
    }

    const assignedEmissionTitle = postit.dataset.assignedEmissionTitle || ''
    const hasConflict = postit.dataset.hasConflict === 'true'

    this.emptyStateTarget.style.display = 'none'
    this.sidebarPanelTarget.style.display = 'block'
    this.slotSummaryTarget.innerHTML = this.buildSlotSummary(postit, assignedEmissionTitle)

    if (hasConflict && !isGhost) {
      this.arbitrationActionsTarget.innerHTML = this.buildConflictArbitrationUI(postit)
    } else {
      this.arbitrationActionsTarget.innerHTML = this.buildArbitrationActions(postit)
    }

    this.arbitrationActionsTarget.style.display =
      this.arbitrationActionsTarget.innerHTML.trim() ? 'flex' : 'none'

    this.slotActionsTarget.style.display = !isLocked && !isGhost && assignedEmissionTitle ? 'block' : 'none'

    if (isGhost) {
      this.setEmissionsListHtml('<div>Ce créneau est annulé ou déplacé. Il ne bloque pas la grille et peut être restauré.</div>')
      return
    }

    if (isLocked) {
      this.setEmissionsListHtml('<div>Ce créneau est verrouillé pour l’affectation, mais peut être ajusté via l’arbitrage.</div>')
      return
    }

    await this.loadCandidatesForSelectedPostit()
  }

  renderEmissions(data, options = {}) {
    const items = Array.isArray(data.items) ? data.items : []
    const showCreateLive = options.showCreateLive === true

    let html = ''

    if (items.length === 0) {
      html += '<div>Aucune émission compatible pour le moment.</div>'
    } else {
      html += items.map((item) => `
        <div
          class="emission-card ${item.isAutoGenerated ? 'is-auto-generated' : ''}"
          data-emission-id="${item.id}"
          data-emission-title="${this.escapeHtml(item.title)}"
          data-emission-duration="${parseInt(item.durationMinutes || 0, 10) || 0}"
          data-action="click->grid#selectEmission"
        >
          ${this.escapeHtml(item.title)}
          <small>
            ${this.escapeHtml(item.meta ?? '')}
            ${item.isAutoGenerated ? ' • direct' : ''}
          </small>
        </div>
      `).join('')
    }

    if (showCreateLive) {
      html += this.buildCreateLiveButton()
    }

    this.setEmissionsListHtml(html)
  }

  renderSpecialEmissions(data, allLoaded = false) {
    const items = Array.isArray(data.items) ? data.items : []
    const categoryId = this.specialCategorySelectTarget.value || ''

    let html = ''

    if (categoryId) {
      html += `
        <div
          class="emission-card is-live-proxy"
          data-special-item-type="manual_live"
          data-category-id="${this.escapeHtml(categoryId)}"
          data-emission-duration="60"
        >
          Créer un direct
          <small>Glisse cette carte dans la grille</small>
        </div>
      `
    }

    if (items.length === 0) {
      html += '<div>Aucune émission trouvée pour cette catégorie.</div>'
    } else {
      html += items.map((item) => `
        <div
          class="emission-card"
          data-special-item-type="manual_emission"
          data-emission-id="${item.id}"
          data-emission-title="${this.escapeHtml(item.title)}"
          data-emission-duration="${parseInt(item.durationMinutes || 0, 10) || 0}"
        >
          ${this.escapeHtml(item.title)}
          <small>
            ${this.escapeHtml(item.meta ?? '')}
            ${item.playLabel ? ` • ${this.escapeHtml(item.playLabel)}` : ''}
          </small>
        </div>
      `).join('')
    }

    this.setEmissionsListHtml(html)

    const emissionsList = this.getCurrentEmissionsListTarget()
    if (emissionsList) {
      emissionsList.querySelectorAll('.emission-card').forEach((card) => {
        this.makeDraggable(card, 'pool')
      })
    }

    const total = parseInt(data.total || items.length || 0, 10)
    const hasMore = data.hasMore === true && allLoaded === false

    this.specialShowAllBtnTarget.style.display = hasMore ? 'block' : 'none'
    this.specialStatusTarget.textContent = `${total} émission(s) disponible(s). Glisse une carte dans la grille.`
  }

  async loadSpecialCandidates() {
    if (this.currentMode !== 'special') {
      return
    }

    const categoryId = this.specialCategorySelectTarget.value || ''

    if (!categoryId) {
      this.specialShowAllBtnTarget.style.display = 'none'
      this.specialStatusTarget.textContent = 'Sélectionne une catégorie pour charger les émissions.'
      this.setEmissionsListHtml('')
      return
    }

    this.specialStatusTarget.textContent = 'Chargement des émissions…'
    this.setEmissionsListHtml('<div>Chargement…</div>')

    try {
      const url = `/admin/grille/special-candidates?categoryId=${encodeURIComponent(categoryId)}&all=0`
      const response = await fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })

      if (!response.ok) {
        throw new Error('Réponse invalide')
      }

      const data = await response.json()
      this.renderSpecialEmissions(data, false)
    } catch (error) {
      this.specialShowAllBtnTarget.style.display = 'none'
      this.specialStatusTarget.textContent = 'Impossible de charger les émissions.'
      this.setEmissionsListHtml('<div>Impossible de charger les émissions.</div>')
    }
  }

  async loadAllSpecialCandidates() {
    if (this.currentMode !== 'special') {
      return
    }

    const categoryId = this.specialCategorySelectTarget.value || ''

    if (!categoryId) {
      return
    }

    this.specialStatusTarget.textContent = 'Chargement complet des émissions…'
    this.setEmissionsListHtml('<div>Chargement…</div>')

    try {
      const url = `/admin/grille/special-candidates?categoryId=${encodeURIComponent(categoryId)}&all=1`
      const response = await fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })

      if (!response.ok) {
        throw new Error('Réponse invalide')
      }

      const data = await response.json()
      this.renderSpecialEmissions(data, true)
    } catch (error) {
      this.specialStatusTarget.textContent = 'Impossible de charger toutes les émissions.'
      this.setEmissionsListHtml('<div>Impossible de charger les émissions.</div>')
    }
  }

  async createSpecialLive() {
    if (this.currentMode !== 'special') {
      return
    }

    const categoryId = this.specialCategorySelectTarget.value || ''

    if (!categoryId) {
      alert('Choisis d’abord une catégorie.')
      return
    }

    alert('Glisse la carte "Créer un direct" dans la grille au créneau souhaité.')
  }

  async selectEmission(event) {
    const card = event.currentTarget
    const emissionId = card.dataset.emissionId
    const emissionsList = this.getCurrentEmissionsListTarget()

    if (this.currentMode === 'special') {
      return
    }

    const slotId = this.selectedPostit?.dataset.slotId || ''
    const startsAt = this.selectedPostit?.dataset.startsAt || ''

    if (!slotId || !startsAt || !emissionId) {
      alert('Informations incomplètes pour affecter cette émission.')
      return
    }

    try {
      const response = await fetch('/admin/grille/assign', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          emissionId,
          startsAt
        })
      })

      if (!response.ok) {
        throw new Error('Réponse invalide')
      }

      const data = await response.json()

      if (!data.success) {
        throw new Error(data.error || 'Erreur inconnue')
      }

      if (data.propagated === true) {
        window.location.reload()
        return
      }

      this.selectedPostit.dataset.emissionIsAutoGenerated = 'false'
      this.selectedPostit.dataset.assignedEmissionId = emissionId
      this.selectedPostit.dataset.assignedEmissionTitle = data.emissionTitle
      this.selectedPostit.classList.add('assigned')

      this.renderPostitContent(this.selectedPostit, data.emissionTitle)

      this.slotSummaryTarget.innerHTML = this.buildSlotSummary(this.selectedPostit, data.emissionTitle)
      this.slotActionsTarget.style.display = 'block'

      if (emissionsList) {
        emissionsList.querySelectorAll('.emission-card').forEach((el) => el.classList.remove('is-selected'))
      }

      card.classList.add('is-selected')

      await this.loadCandidatesForSelectedPostit()
    } catch (error) {
      alert('Erreur lors de l’affectation de l’émission.')
    }
  }

  async createLiveEmission() {
    if (!this.selectedPostit || this.selectedPostit.dataset.slotLocked === 'true') {
      return
    }

    if (this.currentMode !== 'regular') {
      return
    }

    const slotId = this.selectedPostit.dataset.slotId || ''
    const startsAt = this.selectedPostit.dataset.startsAt || ''

    if (!slotId || !startsAt) {
      alert('Informations incomplètes pour créer ce direct.')
      return
    }

    try {
      const response = await fetch('/admin/grille/create-live', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          startsAt
        })
      })

      const data = await response.json().catch(() => null)

      if (!response.ok) {
        throw new Error(data?.error || `Réponse invalide (${response.status})`)
      }

      if (!data.success) {
        throw new Error(data.error || 'Erreur inconnue')
      }

      if (data.propagated === true) {
        window.location.reload()
        return
      }

      this.selectedPostit.dataset.emissionIsAutoGenerated = 'true'
      this.selectedPostit.dataset.assignedEmissionId = data.emissionId
      this.selectedPostit.dataset.assignedEmissionTitle = data.emissionTitle
      this.selectedPostit.classList.add('assigned')

      this.renderPostitContent(this.selectedPostit, data.emissionTitle)

      this.slotSummaryTarget.innerHTML = this.buildSlotSummary(this.selectedPostit, data.emissionTitle)
      this.slotActionsTarget.style.display = 'block'

      await this.loadCandidatesForSelectedPostit()
    } catch (error) {
      console.error(error)
      alert(`Erreur lors de la création du direct : ${error.message}`)
    }
  }

  async removeAssignment() {
    if (!this.selectedPostit || this.selectedPostit.dataset.slotLocked === 'true') {
      return
    }

    if (this.currentMode !== 'regular') {
      return
    }

    const slotId = this.selectedPostit.dataset.slotId || ''
    const startsAt = this.selectedPostit.dataset.startsAt || ''

    if (!slotId || !startsAt) {
      alert('Informations incomplètes pour retirer cette émission.')
      return
    }

    const title = this.selectedPostit.dataset.assignedEmissionTitle || 'Émission inconnue'
    const broadcastRank = parseInt(this.selectedPostit.dataset.broadcastRank || '1', 10)

    let message = ''

    if (broadcastRank === 1) {
      message =
        `Retirer cette émission de la grille ?\n\n` +
        `Émission : ${title}\n` +
        `Première diffusion : ${startsAt}\n\n` +
        `Les rediffusions liées à cette programmation seront aussi retirées.\n\n` +
        `L’émission elle-même ne sera pas supprimée.`
    } else {
      message =
        `Retirer cette rediffusion de la grille ?\n\n` +
        `Émission : ${title}\n` +
        `Rediffusion : ${startsAt}\n\n` +
        `L’émission elle-même ne sera pas supprimée.`
    }

    const confirmed = window.confirm(message)

    if (!confirmed) {
      return
    }

    try {
      const response = await fetch('/admin/grille/remove', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          startsAt
        })
      })

      if (!response.ok) {
        throw new Error('Réponse invalide')
      }

      const data = await response.json()

      if (!data.success) {
        throw new Error(data.error || 'Erreur inconnue')
      }

      if (data.propagated === true) {
        window.location.reload()
        return
      }

      this.selectedPostit.dataset.emissionIsAutoGenerated = 'false'
      this.selectedPostit.dataset.assignedEmissionId = ''
      this.selectedPostit.dataset.assignedEmissionTitle = ''
      this.selectedPostit.classList.remove('assigned')

      this.renderPostitContent(this.selectedPostit)

      this.slotSummaryTarget.innerHTML = this.buildSlotSummary(this.selectedPostit)
      this.slotActionsTarget.style.display = 'none'

      await this.loadCandidatesForSelectedPostit()
    } catch (error) {
      alert('Erreur lors de la suppression de l’émission.')
    }
  }

  async loadCandidatesForSelectedPostit() {
    if (!this.selectedPostit) {
      this.setEmissionsListHtml('<div>Aucune émission compatible pour le moment.</div>')
      return
    }

    const startsAt = this.selectedPostit.dataset.startsAt || ''
    const slotId = this.selectedPostit.dataset.slotId || ''
    const assignedEmissionTitle = this.selectedPostit.dataset.assignedEmissionTitle || ''
    const broadcastRank = parseInt(this.selectedPostit.dataset.broadcastRank || '1', 10)

    if (!slotId || !startsAt) {
      this.setEmissionsListHtml('<div>Impossible de charger les émissions.</div>')
      return
    }

    const search = this.hasRegularSearchTarget
      ? this.regularSearchTarget.value.trim()
      : ''
    const categoryId = (
      this.regularOtherCategory &&
      this.hasRegularCategorySelectTarget
    )
      ? this.regularCategorySelectTarget.value
      : ''
    this.setEmissionsListHtml('<div>Chargement…</div>')

    try {
      const params = new URLSearchParams({
        slotId,
        startsAt,
        extended: this.regularExtended ? '1' : '0'
      })

      if (categoryId) {
        params.set('categoryId', categoryId)
      }

      if (search.length >= 3) {
        params.set('q', search)
      }

      const response = await fetch(`/admin/grille/candidates?${params.toString()}`, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })

      if (!response.ok) {
        throw new Error('Réponse invalide')
      }

      const data = await response.json()
      const items = Array.isArray(data.items) ? data.items : []
      const hasAutoGeneratedCandidate = items.some((item) => item.isAutoGenerated === true)

      this.renderEmissions(data, {
        showCreateLive: !categoryId && !assignedEmissionTitle && broadcastRank === 1 && !hasAutoGeneratedCandidate
      })
    } catch (error) {
      this.setEmissionsListHtml('<div>Impossible de charger les émissions.</div>')
    }
  }

  toggleCustomRescheduleForm() {
    if (!this.arbitrationActionsTarget) {
      return
    }

    const form = this.arbitrationActionsTarget.querySelector('[data-grid-custom-reschedule-form]')
    if (!form) {
      return
    }

    const isHidden = form.style.display === 'none' || form.style.display === ''
    form.style.display = isHidden ? 'flex' : 'none'
  }

  async reschedulePreviousWeek() {
    await this.submitWeekReschedule('previous')
  }

  async rescheduleNextWeek() {
    await this.submitWeekReschedule('next')
  }

  async submitWeekReschedule(direction) {
    if (!this.selectedPostit) {
      return
    }

    const slotId = this.selectedPostit.dataset.slotId || ''
    const startsAt = this.selectedPostit.dataset.startsAt || ''

    if (!slotId || !startsAt) {
      alert('Informations incomplètes pour déplacer ce créneau.')
      return
    }

    try {
      const response = await fetch('/admin/grille/reschedule-week', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          startsAt,
          direction
        })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'Erreur inconnue')
      }

      if (!data.targetWeekStart) {
        throw new Error('Semaine cible manquante')
      }

      window.location.href = `/admin/grille/${data.targetWeekStart}`
    } catch (error) {
      alert(`Erreur lors du déplacement : ${error.message}`)
    }
  }

  async submitCustomReschedule() {
    if (!this.selectedPostit) {
      return
    }

    const slotId = this.selectedPostit.dataset.slotId || ''
    const startsAt = this.selectedPostit.dataset.startsAt || ''

    const dateInput = this.arbitrationActionsTarget.querySelector('[data-grid-custom-date]')
    const timeInput = this.arbitrationActionsTarget.querySelector('[data-grid-custom-time]')

    const newDate = dateInput?.value || ''
    const newTime = timeInput?.value || ''

    if (!slotId || !startsAt || !newDate || !newTime) {
      alert('Merci de renseigner une date et une heure.')
      return
    }

    try {
      const response = await fetch('/admin/grille/reschedule-custom', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          startsAt,
          newDate,
          newTime
        })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'Erreur inconnue')
      }

      if (!data.targetWeekStart) {
        throw new Error('Semaine cible manquante')
      }

      window.location.href = `/admin/grille/${data.targetWeekStart}`
    } catch (error) {
      alert(`Erreur lors du déplacement personnalisé : ${error.message}`)
    }
  }

  async cancelOccurrence() {
    if (!this.selectedPostit) {
      return
    }

    const slotId = this.selectedPostit.dataset.slotId || ''
    const startsAt = this.selectedPostit.dataset.startsAt || ''

    if (!slotId || !startsAt) {
      alert('Informations incomplètes pour annuler cette occurrence.')
      return
    }

    const confirmed = window.confirm('Annuler cette occurrence de la grille ?')
    if (!confirmed) {
      return
    }

    try {
      const response = await fetch('/admin/grille/cancel-occurrence', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          startsAt
        })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'Erreur inconnue')
      }

      window.location.reload()
    } catch (error) {
      alert(`Erreur lors de l’annulation : ${error.message}`)
    }
  }

  async clearReschedule() {
    if (!this.selectedPostit) {
      return
    }

    const slotId = this.selectedPostit.dataset.slotId || ''
    const originalStartsAt = this.selectedPostit.dataset.originalStartsAt || ''

    if (!slotId || !originalStartsAt) {
      alert('Informations incomplètes pour revenir au créneau d’origine.')
      return
    }

    try {
      const response = await fetch('/admin/grille/clear-reschedule', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          originalStartsAt
        })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'Erreur inconnue')
      }

      if (!data.targetWeekStart) {
        throw new Error('Semaine cible manquante')
      }

      window.location.href = `/admin/grille/${data.targetWeekStart}`
    } catch (error) {
      alert(`Erreur lors du retour au créneau d’origine : ${error.message}`)
    }
  }

  getConflictItem(index) {
    if (!this.selectedPostit) {
      return null
    }

    const occurrences = this.buildConflictGroup(this.selectedPostit)
      .filter((item) => item.isSelectedOccurrence !== true)

    const numericIndex = parseInt(index, 10)

    if (Number.isNaN(numericIndex)) {
      return null
    }

    return occurrences[numericIndex] || null
  }

  async arbitratePreviousWeek(event) {
    const item = this.getConflictItem(event.currentTarget.dataset.conflictIndex)
    if (!item) {
      alert('Occurrence de conflit introuvable.')
      return
    }

    await this.callConflictReschedule(item, 'previous')
  }

  async arbitrateNextWeek(event) {
    const item = this.getConflictItem(event.currentTarget.dataset.conflictIndex)
    if (!item) {
      alert('Occurrence de conflit introuvable.')
      return
    }

    await this.callConflictReschedule(item, 'next')
  }

  async arbitrateCancel(event) {
    const item = this.getConflictItem(event.currentTarget.dataset.conflictIndex)
    if (!item) {
      alert('Occurrence de conflit introuvable.')
      return
    }

    const confirmed = window.confirm('Annuler cette occurrence en conflit ?')
    if (!confirmed) {
      return
    }

    await this.callConflictCancel(item)
  }

  async callConflictReschedule(item, direction) {
    const slotId = item.slotId || ''
    const startsAt = item.startsAt || ''

    if (!slotId || !startsAt) {
      alert('Informations incomplètes pour déplacer cette occurrence en conflit.')
      return
    }

    try {
      const response = await fetch('/admin/grille/reschedule-week', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          startsAt,
          direction
        })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'Erreur inconnue')
      }

      if (!data.targetWeekStart) {
        throw new Error('Semaine cible manquante')
      }

      window.location.href = `/admin/grille/${data.targetWeekStart}`
    } catch (error) {
      alert(`Erreur lors du déplacement du conflit : ${error.message}`)
    }
  }

  async callConflictCancel(item) {
    const slotId = item.slotId || ''
    const startsAt = item.startsAt || ''

    if (!slotId || !startsAt) {
      alert('Informations incomplètes pour annuler cette occurrence en conflit.')
      return
    }

    try {
      const response = await fetch('/admin/grille/cancel-occurrence', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          startsAt
        })
      })

      const data = await response.json()

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'Erreur inconnue')
      }

      window.location.reload()
    } catch (error) {
      alert(`Erreur lors de l’annulation du conflit : ${error.message}`)
    }
  }

  saveCurrentMode() {
    sessionStorage.setItem('gridCurrentMode', this.currentMode || 'regular')
  }

  getSavedMode() {
    return sessionStorage.getItem('gridCurrentMode') || 'regular'
  }

  async toggleRegularOtherCategory() {
    this.regularOtherCategory = !this.regularOtherCategory

    if (this.hasRegularOtherCategoryBtnTarget) {
      this.regularOtherCategoryBtnTarget.classList.toggle('is-active', this.regularOtherCategory)
      this.regularOtherCategoryBtnTarget.textContent = this.regularOtherCategory
        ? 'Revenir à la catégorie du créneau'
        : 'Autre catégorie'
    }

    if (this.hasRegularCategorySelectTarget) {
      this.regularCategorySelectTarget.style.display = this.regularOtherCategory ? 'block' : 'none'

      if (!this.regularOtherCategory) {
        this.regularCategorySelectTarget.value = ''
      }
    }

    await this.loadCandidatesForSelectedPostit()
  }

  async regularCategoryChanged() {
    if (!this.regularOtherCategory) {
      this.regularOtherCategory = true
    }

    await this.loadCandidatesForSelectedPostit()
  }

  async restoreOccurrence() {
    if (!this.selectedPostit) {
      return
    }

    const slotId = this.selectedPostit.dataset.slotId || ''
    const originalStartsAt = this.selectedPostit.dataset.originalStartsAt || this.selectedPostit.dataset.startsAt || ''

    if (!slotId || !originalStartsAt) {
      alert('Informations incomplètes pour restaurer ce créneau.')
      return
    }

    const confirmed = window.confirm('Restaurer ce créneau régulier ?')

    if (!confirmed) {
      return
    }

    try {
      const response = await fetch('/admin/grille/restore-occurrence', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          slotId,
          originalStartsAt
        })
      })

      const data = await response.json().catch(() => null)

      if (!response.ok || !data?.success) {
        throw new Error(data?.error || 'Erreur inconnue')
      }

      if (data.targetWeekStart) {
        window.location.href = `/admin/grille/${data.targetWeekStart}`
        return
      }

      window.location.reload()
    } catch (error) {
      alert(`Erreur lors de la restauration : ${error.message}`)
    }
  }
}