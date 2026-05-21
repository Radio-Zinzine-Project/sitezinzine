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
        this.dayTargets.forEach((day) => {
            day.classList.remove('drag-over')
        })

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

export function dropOnDay(e, dayEl) {
    dayEl.classList.remove('drag-over')

    if (!this.dragged || this.dragged.dataset.slotLocked === 'true') {
        return
    }

    const rect = dayEl.getBoundingClientRect()
    const startIndex = Math.floor((e.clientY - rect.top) / this.CELL_H)

    if (
        this.dragged.dataset.source === 'pool' &&
        this.dragged.dataset.specialItemType
    ) {
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

export function placePostIt(dayEl, startIndex) {
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

export function dropBackToPool(e, pool) {
    e.preventDefault()

    pool.classList.remove('drop-pool-hover')

    if (
        !this.dragged ||
        this.dragged.dataset.source !== 'grid' ||
        this.dragged.dataset.slotLocked === 'true'
    ) {
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