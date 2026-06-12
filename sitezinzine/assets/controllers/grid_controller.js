import { Controller } from '@hotwired/stimulus'
import {
  askRebroadcastStrategy,
  askRestoreRebroadcastStrategy
} from './grid/rebroadcastModal'
import * as arbitration from './grid/arbitration'
import * as drafts from './grid/drafts'
import * as emissions from './grid/emissions'
import * as conflicts from './grid/conflicts'
import * as sidebar from './grid/sidebar'
import * as postitRenderer from './grid/postitRenderer'
import * as dragDrop from './grid/dragDrop'
import { escapeHtml } from './grid/utils'

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
    'specialSearch',
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
    this.specialSearchTimeout = null

    this.syncSidebarHeight = this.syncSidebarHeight.bind(this)

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

    this.restoreScrollTarget()

    this.syncSidebarHeight()
    window.addEventListener('resize', this.syncSidebarHeight)
  }

  saveScrollTarget(startsAt) {
    if (!startsAt) {
      return
    }

    sessionStorage.setItem(
      'gridScrollTargetStartsAt',
      startsAt
    )
  }

  restoreScrollTarget() {
    const startsAt = sessionStorage.getItem(
      'gridScrollTargetStartsAt'
    )

    if (!startsAt) {
      return
    }

    sessionStorage.removeItem(
      'gridScrollTargetStartsAt'
    )

    requestAnimationFrame(() => {
      const target = this.element.querySelector(
        `[data-starts-at="${CSS.escape(startsAt)}"]`
      )

      if (!target) {
        return
      }

      target.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
      })

      target.classList.add('is-selected')
      this.selectedPostit = target
    })
  }

  disconnect() {
    window.removeEventListener('resize', this.syncSidebarHeight)
  }

  syncSidebarHeight() {
    const grid = this.element.querySelector('.schedule-grid')
    const sidebar = this.element.querySelector('.sidebar')

    if (!grid || !sidebar) {
      return
    }

    sidebar.style.height = `${grid.offsetHeight}px`
    sidebar.style.overflowY = 'auto'
    sidebar.style.overflowX = 'hidden'
  }

  regularSearch() {
    clearTimeout(this.regularSearchTimeout)

    this.regularSearchTimeout = setTimeout(() => {
      this.loadCandidatesForSelectedPostit()
    }, 300)
  }

  specialSearch() {
    clearTimeout(this.specialSearchTimeout)

    this.specialSearchTimeout = setTimeout(() => {
      this.loadSpecialCandidates()
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
    this.clearSpecialSearch()
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
    this.clearRegularSearch()
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

  specialCategoryChanged() {
    this.clearSpecialSearch()
    return this.loadSpecialCandidates()
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
    return dragDrop.makeDraggable.call(this, el, source)
  }

  dropOnDay(e, dayEl) {
    return dragDrop.dropOnDay.call(this, e, dayEl)
  }

  canDropInTrash() {
    return drafts.canDropInTrash.call(this)
  }

  async dropOnTrash(event) {
    return drafts.dropOnTrash.call(this, event)
  }

  escapeHtml(value) {
    return escapeHtml(value)
  }

  getStatusLabel(postit) {
    return sidebar.getStatusLabel(postit)
  }

  applyPostitVariant(postit) {
    return postitRenderer.applyPostitVariant(postit)
  }

  applyConflictState(postit) {
    return postitRenderer.applyConflictState(postit)
  }

  getConflictSeverityLabel(postit) {
    return conflicts.getConflictSeverityLabel(postit)
  }

  getConflictTypeLabel(postit) {
    return conflicts.getConflictTypeLabel(postit)
  }

  parseConflictWith(postit) {
    return conflicts.parseConflictWith(postit)
  }

  buildConflictSummary(postit) {
    return conflicts.buildConflictSummary(this, postit)
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


  buildSlotSummary(postit, title) {
    return sidebar.buildSlotSummary(
      this,
      postit,
      title
    )
  }

  buildSpecialSlotSummary(postit) {
    return sidebar.buildSpecialSlotSummary(
      this,
      postit
    )
  }


  buildCreateLiveButton() {
    return `
      <div div class="create-live-box" >
        <button
          type="button"
          class="btn-create-live"
          data-action="click->grid#createLiveEmission"
        >
          Créer un direct
        </button>
      </div >
      `
  }

  buildArbitrationActions(postit) {
    const canRestore = postit.dataset.canRestore === 'true'
    const isCancelled = postit.dataset.isCancelled === 'true'
    const isRescheduledOrigin = postit.dataset.isRescheduledOrigin === 'true'

    if (canRestore || isCancelled || isRescheduledOrigin) {
      return `
      <div div class="slot-actions" >
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
      </div >
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
      <div div class="slot-actions" >
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
      </div >
      `
    }

    return `
      <div div class="slot-actions" >
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

  buildConflictGroup(postit) {
    return conflicts.buildConflictGroup(this, postit)
  }

  buildOccurrenceTypeLabel(item) {
    return conflicts.buildOccurrenceTypeLabel(item)
  }

  buildConflictActionButton(label, action, slotId, startsAt) {
    return conflicts.buildConflictActionButton(
      label,
      action,
      slotId,
      startsAt
    )
  }

  buildConflictHeader(postit) {
    return conflicts.buildConflictHeader(
      this,
      postit
    )
  }

  buildConflictArbitrationUI(postit) {
    return conflicts.buildConflictArbitrationUI(this, postit)
  }

  getPostitLabels(postit, title = '') {
    return postitRenderer.getPostitLabels(postit, title)
  }

  getBadgeText(postit) {
    return postitRenderer.getBadgeText(postit)
  }

  renderPostitContent(postit, title = null) {
    return postitRenderer.renderPostitContent(
      this,
      postit,
      title
    )
  }

  placePostIt(dayEl, startIndex) {
    return dragDrop.placePostIt.call(this, dayEl, startIndex)
  }

  getStartsAtFromDrop(dayEl, startIndex) {
    return dragDrop.getStartsAtFromDrop.call(
      this,
      dayEl,
      startIndex
    )
  }

  async createSpecialDraftFromDrop(dayEl, startIndex) {
    return drafts.createSpecialDraftFromDrop.call(this, dayEl, startIndex)
  }

  async moveManualDraftFromDrop(dayEl, startIndex) {
    return drafts.moveManualDraftFromDrop.call(
      this,
      dayEl,
      startIndex
    )
  }

  dropBackToPool(e, pool) {
    return dragDrop.dropBackToPool.call(
      this,
      e,
      pool
    )
  }

  async selectSlot(event) {
    return sidebar.selectSlot(this, event)
  }

  renderEmissions(data, options = {}) {
    return emissions.renderEmissions(
      this,
      data,
      options
    )
  }

  renderSpecialEmissions(data, showAll = false) {
    return emissions.renderSpecialEmissions(
      this,
      data,
      showAll
    )
  }

  async loadSpecialCandidates() {
    return emissions.loadSpecialCandidates.call(this)
  }

  async loadAllSpecialCandidates() {
    return emissions.loadAllSpecialCandidates.call(this)
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

  async createLiveEmission() {
    return emissions.createLiveEmission.call(this)
  }

  async removeAssignment() {
    return emissions.removeAssignment.call(this)
  }

  async loadCandidatesForSelectedPostit() {
    return emissions.loadCandidatesForSelectedPostit.call(this)
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
    return arbitration.submitWeekReschedule.call(this, direction)
  }

  async submitCustomReschedule() {
    return arbitration.submitCustomReschedule.call(this)
  }

  async cancelOccurrence() {
    return arbitration.cancelOccurrence.call(this)
  }

  async restoreOccurrence() {
    return arbitration.restoreOccurrence.call(this)
  }

  async clearReschedule() {
    return arbitration.clearReschedule.call(this)
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
    return arbitration.callConflictReschedule.call(this, item, direction)
  }

  async callConflictCancel(item) {
    return arbitration.callConflictCancel.call(this, item)
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

  askRebroadcastStrategy(actionLabel = 'modifier') {
    return askRebroadcastStrategy(this, actionLabel)
  }

  askRestoreRebroadcastStrategy() {
    return askRestoreRebroadcastStrategy(this)
  }

  async selectEmission(event) {
    return emissions.selectEmission.call(this, event)
  }

  openRebroadcastModal() {
    return drafts.openRebroadcastModal.call(this)
  }

  loadLinkedDiffusions() {
    return sidebar.loadLinkedDiffusions.call(this)
  }

  clearRegularSearch() {
    return emissions.clearRegularSearch.call(this)
  }

  clearSpecialSearch() {
    return emissions.clearSpecialSearch.call(this)
  }

}