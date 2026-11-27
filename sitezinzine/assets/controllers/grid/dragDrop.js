export function makeDraggable(el, source) {
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

    const listenerOptions = { signal: this.eventListenersAbortController?.signal }
    el.addEventListener('dragstart', () => {
        this.dragged = el

        if (el.dataset.source === 'grid') {
            this.fromDay = el.closest('.day-col')

            const index = Number(el.dataset.startIndex)
            this.fromStartIndex = this.timeGeometry.indexToPosition(index).kind === 'slot' ? index : null
        } else {
            this.fromDay = null
            this.fromStartIndex = null
        }
    }, listenerOptions)

    el.addEventListener('dragend', () => clearDrag.call(this), listenerOptions)
}

function clearDrag() {
    this.dayTargets.forEach(day => day.classList.remove('drag-over'))
    if (this.hasTrashZoneTarget) {
        this.trashZoneTarget.classList.remove('is-active')
    }
    this.dragged = null
    this.fromDay = null
    this.fromStartIndex = null
}

function dropPosition(context, event, dayEl) {
    return context.timeGeometry.positionToIndex(event.clientY - dayEl.getBoundingClientRect().top)
}

export function dragOverDay(event, dayEl) {
    const valid = this.dragged && this.dragged.dataset.slotLocked !== 'true'
        && dropPosition(this, event, dayEl).kind === 'slot'
    if (valid) {
        event.preventDefault()
    }
    dayEl.classList.toggle('drag-over', Boolean(valid))
}

function validateInterval(context, startIndex, duration) {
    const start = startIndex * context.CELL_MIN
    const result = Number.isSafeInteger(duration) && duration > 0
        ? context.timeGeometry.classifyInterval(start, start + duration)
        : 'invalid'
    if (result === 'clear') {
        return true
    }

    alert(result === 'overlap'
        ? 'Cette programmation empiète sur la plage sans programmation de 01:00 à 06:30.'
        : 'La durée de cette programmation est invalide ou indisponible.')
    clearDrag.call(context)
    return false
}

export function dropOnDay(e, dayEl) {
    dayEl.classList.remove('drag-over')

    if (
        !this.dragged
        || this.dragged.dataset.slotLocked === 'true'
    ) {
        return
    }

    const destination = dropPosition(this, e, dayEl)
    if (destination.kind !== 'slot') {
        clearDrag.call(this)
        return
    }
    e.preventDefault()

    const { index } = destination
    const { source, specialItemType, isManualDraft, duration, emissionDuration } = this.dragged.dataset
    if (source === 'pending-rebroadcast') {
        if (validateInterval(this, index, Number(duration))) {
            return this.placePendingRebroadcastFromDrop(dayEl, index)
        }
    } else if (source === 'pool' && specialItemType) {
        if (validateInterval(this, index, Number(emissionDuration))) {
            return this.createSpecialDraftFromDrop(dayEl, index)
        }
    } else if (source === 'grid' && isManualDraft === 'true') {
        if (validateInterval(this, index, Number(duration))) {
            return this.moveManualDraftFromDrop(dayEl, index)
        }
    } else {
        return this.placePostIt(dayEl, index)
    }
}

export function placePostIt(dayEl, startIndex) {
    if (!this.dragged || this.dragged.dataset.slotLocked === 'true') {
        return
    }

    if (this.timeGeometry.indexToPosition(startIndex).kind !== 'slot') {
        clearDrag.call(this)
        return
    }

    const duration = Number(this.dragged.dataset.duration || this.dragged.dataset.emissionDuration)
    if (!Number.isSafeInteger(duration) || duration <= 0) {
        validateInterval(this, startIndex, duration)
        return
    }
    const heightPx = this.durationToPx(duration)
    const cells = this.durationToCells(duration)
    startIndex = Math.max(0, Math.min(startIndex, this.daySlots - cells))

    if (!validateInterval(this, startIndex, duration)) {
        return
    }

    if (this.dragged.dataset.source === 'pool') {
        dayEl.appendChild(this.dragged)
        this.dragged.dataset.source = 'grid'
    }

    this.dragged.dataset.duration = String(duration)

    this.dragged.dataset.startIndex = String(startIndex)
    this.dragged.classList.add('postit', 'time-position')
    this.dragged.style.removeProperty('top')
    this.dragged.style.setProperty('--time-index', String(startIndex))
    this.dragged.style.setProperty('--after-break', startIndex >= this.breakEnd ? '1' : '0')
    this.dragged.style.left = '3px'
    this.dragged.style.right = '3px'
    this.dragged.style.height = `${heightPx}px`

    this.fromDay = dayEl
    this.fromStartIndex = startIndex
}

export function getStartsAtFromDrop(dayEl, startIndex) {
    const weekStart = this.getWeekStart()

    const dayIndex =
        parseInt(dayEl.dataset.dayIndex || '0', 10)

    const minutesFromMidnight =
        startIndex * this.CELL_MIN

    const hours =
        Math.floor(minutesFromMidnight / 60)

    const minutes =
        minutesFromMidnight % 60

    const baseDate =
        new Date(`${weekStart}T00:00:00`)

    baseDate.setDate(
        baseDate.getDate() + dayIndex
    )

    baseDate.setHours(
        hours,
        minutes,
        0,
        0
    )

    const yyyy = baseDate.getFullYear()
    const mm = String(
        baseDate.getMonth() + 1
    ).padStart(2, '0')

    const dd = String(
        baseDate.getDate()
    ).padStart(2, '0')

    const hh = String(
        baseDate.getHours()
    ).padStart(2, '0')

    const mi = String(
        baseDate.getMinutes()
    ).padStart(2, '0')

    return `${yyyy}-${mm}-${dd} ${hh}:${mi}:00`
}
