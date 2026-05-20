import { escapeHtml } from './utils'

export function getConflictSeverityLabel(postit) {
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

export function getConflictTypeLabel(postit) {
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

export function parseConflictWith(postit) {
    const raw = postit.dataset.conflictWith || '[]'

    try {
        const parsed = JSON.parse(raw)
        return Array.isArray(parsed) ? parsed : []
    } catch {
        return []
    }
}

export function buildConflictSummary(context, postit) {
    const hasConflict = postit.dataset.hasConflict === 'true'

    if (!hasConflict) {
        return ''
    }

    const conflictCount = parseInt(postit.dataset.conflictCount || '0', 10)
    const severityLabel = context.getConflictSeverityLabel(postit)
    const typeLabel = context.getConflictTypeLabel(postit)
    const conflicts = context.parseConflictWith(postit)

    let html = `
    <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ececec;">
      <div><span class="label">Conflit :</span> ${escapeHtml(typeLabel)}</div>
      <div><span class="label">Niveau :</span> ${escapeHtml(severityLabel)}</div>
      <div><span class="label">Nombre :</span> ${escapeHtml(conflictCount)}</div>
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
          <div>${escapeHtml(categoryTitle)}</div>
          ${ruleDisplayName ? `<div style="font-size:12px; color:#555;">${escapeHtml(ruleDisplayName)}</div>` : ''}
          <div style="font-size:12px; color:#555;">
            ${escapeHtml(startsAt)}${endsAt ? ` → ${escapeHtml(endsAt)}` : ''}
          </div>
          <div style="font-size:12px; color:#555;">${escapeHtml(typeLabelItem)}</div>
        </li>
      `
        })

        html += '</ul>'
    }

    html += '</div>'

    return html
}

export function buildConflictActionButton(
    label,
    action,
    slotId,
    startsAt
) {
    return `
    <button
      type="button"
      class="conflict-action-btn"
      data-action="${action}"
      data-slot-id="${slotId}"
      data-starts-at="${startsAt}"
    >
      ${label}
    </button>
  `
}

export function buildConflictHeader(context, postit) {
    const severity =
        context.getConflictSeverityLabel(postit)

    const type =
        context.getConflictTypeLabel(postit)

    return `
    <div class="conflict-panel__header">

      <div class="conflict-panel__title">
        ⚠ ${escapeHtml(type)}
      </div>

      <div class="conflict-panel__severity">
        ${escapeHtml(severity)}
      </div>

    </div>
  `
}

export function buildConflictArbitrationUI(context, postit) {
    const hasConflict = postit.dataset.hasConflict === 'true'

    if (!hasConflict) {
        return ''
    }

    const occurrences = context.buildConflictGroup(postit)

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
        const typeLabel = context.buildOccurrenceTypeLabel(item)
        const isProjectedOverride = item.isProjectedOverride === true
        const isSelected = item.isSelectedOccurrence === true

        const badge = isSelected
            ? '<span class="conflict-card__badge">Sélectionné</span>'
            : '<span class="conflict-card__badge conflict-card__badge--other">En conflit</span>'

        let actionsHtml = ''

        if (isSelected && isProjectedOverride) {
            actionsHtml = `
        <button type="button" class="btn-arbitration" data-action="click->grid#clearReschedule">
          Revenir au créneau d’origine
        </button>

        <button type="button" class="btn-arbitration btn-arbitration--danger" data-action="click->grid#cancelOccurrence">
          Annuler ce créneau
        </button>
      `
        } else if (isSelected) {
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
      <div class="conflict-card ${isSelected ? 'conflict-card--selected' : ''}">
        <div class="conflict-card__header">
          <div class="conflict-card__title">${escapeHtml(categoryTitle)}</div>
          ${badge}
        </div>

        ${ruleDisplayName ? `<div class="conflict-card__meta">${escapeHtml(ruleDisplayName)}</div>` : ''}

        <div class="conflict-card__meta">
          ${escapeHtml(startsAt)}${endsAt ? ` → ${escapeHtml(endsAt)}` : ''}
        </div>

        <div class="conflict-card__meta">
          ${escapeHtml(typeLabel)}
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

export function buildConflictGroup(context, postit) {
    const group = []

    const selectedOccurrence = {
        slotId: postit.dataset.slotId || '',
        categoryTitle: postit.dataset.categoryTitle || '',
        startsAt:
            postit.dataset.originalStartsAt ||
            postit.dataset.startsAt ||
            '',
        endsAt: postit.dataset.endsAt || '',
        ruleDisplayName: postit.dataset.ruleDisplayName || '',
        broadcastRank: parseInt(postit.dataset.broadcastRank || '1', 10),
        isProjectedOverride: postit.dataset.isProjectedOverride === 'true',
        isSelectedOccurrence: true
    }

    group.push(selectedOccurrence)

    const conflictItems = context.parseConflictWith(postit)

    conflictItems.forEach((item) => {
        group.push({
            ...item,
            isSelectedOccurrence: false
        })
    })

    return group
}

export function buildOccurrenceTypeLabel(item) {
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