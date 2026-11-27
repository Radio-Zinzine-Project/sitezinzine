const MINUTES_PER_DAY = 24 * 60

export function createTimeGeometry({
    slotMinutes, slotHeight, daySlots, breakStart, breakEnd, breakHeight,
}) {
    const dayHeight = daySlots * slotHeight
    if (![slotMinutes, slotHeight, daySlots, breakStart, breakEnd, breakHeight, dayHeight]
        .every(Number.isSafeInteger)
        || slotMinutes <= 0 || slotHeight <= 0 || daySlots * slotMinutes !== MINUTES_PER_DAY
        || breakStart < 0 || breakEnd <= breakStart || breakEnd > daySlots) {
        throw new RangeError('Invalid time geometry configuration')
    }

    const hiddenSlots = breakEnd - breakStart
    const visibleSlots = daySlots - hiddenSlots
    const hiddenHeight = hiddenSlots * slotHeight
    if (breakHeight <= 0 || breakHeight >= hiddenHeight) {
        throw new RangeError('The break must be shorter than the hidden slots')
    }

    const compression = hiddenHeight - breakHeight
    const totalHeight = dayHeight - compression
    const breakTop = breakStart * slotHeight
    const breakBottom = breakTop + breakHeight
    const breakStartMinute = breakStart * slotMinutes
    const breakEndMinute = breakEnd * slotMinutes

    function boundaryToPosition(index) {
        if (!Number.isInteger(index) || index < 0 || index > daySlots) {
            return { kind: 'invalid' }
        }
        if (index > breakStart && index < breakEnd) {
            return { kind: 'break' }
        }
        const offset = index >= breakEnd ? compression : 0
        return { kind: 'boundary', position: index * slotHeight - offset }
    }

    function indexToPosition(index) {
        if (index === daySlots) {
            return { kind: 'invalid' }
        }
        if (index === breakStart) {
            return { kind: 'break' }
        }
        const result = boundaryToPosition(index)
        return result.kind === 'boundary' ? { kind: 'slot', position: result.position } : result
    }

    function positionToIndex(position) {
        if (!Number.isFinite(position) || position < 0 || position >= totalHeight) {
            return { kind: 'outside' }
        }
        if (position >= breakTop && position < breakBottom) {
            return { kind: 'break' }
        }
        if (position < breakTop) {
            return { kind: 'slot', index: Math.floor(position / slotHeight) }
        }
        return { kind: 'slot', index: breakEnd + Math.floor((position - breakBottom) / slotHeight) }
    }

    // Supply civil minutes relative to a common midnight. Add one day to an
    // end on the following date. Fractional minutes keep sub-minute precision.
    function classifyInterval(start, end) {
        if (!Number.isFinite(start) || !Number.isFinite(end) || start < 0 || end <= start) {
            return 'invalid'
        }

        const startInDay = start % MINUTES_PER_DAY
        // Find the first break that has not already ended at the interval start.
        const nextBreakStart = startInDay < breakEndMinute
            ? breakStartMinute
            : MINUTES_PER_DAY + breakStartMinute
        return end - start > nextBreakStart - startInDay ? 'overlap' : 'clear'
    }

    return {
        hiddenSlots, visibleSlots, hiddenHeight, compression, totalHeight, breakTop, breakBottom,
        indexToPosition, positionToIndex, boundaryToPosition, classifyInterval,
    }
}
