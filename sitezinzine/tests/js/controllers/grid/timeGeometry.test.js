import { describe, expect, test } from 'vitest'
import { createTimeGeometry } from '../../../../assets/controllers/grid/timeGeometry'

const config = {
    slotMinutes: 15,
    slotHeight: 14,
    daySlots: 96,
    breakStart: 4,
    breakEnd: 26,
    breakHeight: 28,
}
const geometry = () => createTimeGeometry(config)
const visibleIndices = Array.from({ length: 96 }, (_, index) => index)
    .filter(index => index < 4 || index >= 26)

describe('Géométrie temporelle', () => {
    test('dérive les dimensions de la configuration', () => {
        expect(geometry()).toMatchObject({
            hiddenSlots: 22, visibleSlots: 74, hiddenHeight: 308,
            compression: 280, totalHeight: 1064, breakTop: 56, breakBottom: 84,
        })
    })

    test.each([
        [0, 0], [1, 14], [2, 28], [3, 42], [26, 84], [27, 98],
        [28, 112], [48, 392], [72, 728], [95, 1050],
    ])('place le créneau %i à %i px', (index, position) => {
        expect(geometry().indexToPosition(index)).toEqual({ kind: 'slot', position })
    })

    test.each([4, 12, 25])('identifie le créneau masqué %i', index => {
        expect(geometry().indexToPosition(index)).toEqual({ kind: 'break' })
    })

    test.each([-1, 96, 97, 2.5, NaN, Infinity, -Infinity, '26'])(
        'refuse l’index de début invalide %s', index => {
            expect(geometry().indexToPosition(index)).toEqual({ kind: 'invalid' })
        },
    )

    test.each([[4, 56], [26, 84], [96, 1064]])(
        'représente la borne %i à %i px', (index, position) => {
            expect(geometry().boundaryToPosition(index)).toEqual({ kind: 'boundary', position })
        },
    )

    test('distingue une borne masquée et une borne invalide', () => {
        expect(geometry().boundaryToPosition(5)).toEqual({ kind: 'break' })
        expect(geometry().boundaryToPosition(97)).toEqual({ kind: 'invalid' })
    })

    test.each([
        [0, 0], [13.99, 0], [14, 1], [42, 3], [55.99, 3], [84, 26],
        [97.99, 26], [98, 27], [392, 48], [1050, 95], [1063.99, 95],
    ])('convertit %s px en index réel %i', (position, index) => {
        expect(geometry().positionToIndex(position)).toEqual({ kind: 'slot', index })
    })

    test.each([56, 70, 83.99])('identifie la bande à %s px', position => {
        expect(geometry().positionToIndex(position)).toEqual({ kind: 'break' })
    })

    test.each([-0.01, 1064, 1065, NaN, Infinity, -Infinity, '84'])(
        'refuse la coordonnée hors grille %s', position => {
            expect(geometry().positionToIndex(position)).toEqual({ kind: 'outside' })
        },
    )

    test.each(visibleIndices)('retrouve l’index %i au bord et à l’intérieur du créneau', index => {
        const axis = geometry()
        const { position } = axis.indexToPosition(index)
        const expected = { kind: 'slot', index }
        expect(axis.positionToIndex(position)).toEqual(expected)
        expect(axis.positionToIndex(position + 7)).toEqual(expected)
    })

    test('recalcule les dimensions et les conversions avec une autre configuration', () => {
        const axis = createTimeGeometry({
            slotMinutes: 30, slotHeight: 10, daySlots: 48,
            breakStart: 2, breakEnd: 6, breakHeight: 15,
        })
        expect(axis).toMatchObject({
            hiddenSlots: 4, visibleSlots: 44, hiddenHeight: 40,
            compression: 25, totalHeight: 455, breakTop: 20, breakBottom: 35,
        })
        expect(axis.indexToPosition(6)).toEqual({ kind: 'slot', position: 35 })
        expect(axis.positionToIndex(34)).toEqual({ kind: 'break' })
        expect(axis.positionToIndex(35)).toEqual({ kind: 'slot', index: 6 })
        expect(axis.boundaryToPosition(48)).toEqual({ kind: 'boundary', position: 455 })
        expect(axis.classifyInterval(45, 60)).toBe('clear')
        expect(axis.classifyInterval(45, 61)).toBe('overlap')
    })

    test.each([
        { slotMinutes: 0 }, { slotHeight: Infinity }, { daySlots: 95 },
        { breakStart: -1 }, { breakEnd: 4 }, { breakEnd: 97 },
        { breakHeight: 0 }, { breakHeight: 309 },
    ])('refuse une configuration incohérente %o', overrides => {
        expect(() => createTimeGeometry({ ...config, ...overrides })).toThrow(RangeError)
    })
})

describe('Intersection avec la plage nocturne', () => {
    test.each([
        [0, 45, 'clear'], [0, 60, 'clear'], [45, 60, 'clear'],
        [45, 61, 'overlap'], [45, 105, 'overlap'], [60, 75, 'overlap'],
        [180, 240, 'overlap'], [375, 390, 'overlap'], [375, 405, 'overlap'],
        [390, 420, 'clear'], [720, 810, 'clear'], [1425, 1440, 'clear'],
        [1410, 1470, 'clear'], [1410, 1500, 'clear'], [1410, 1515, 'overlap'],
        [1410, 2880, 'overlap'], [1500, 1500.5, 'overlap'],
        [1830, 1860, 'clear'],
    ])('classe [%s, %s[ comme %s', (start, end, expected) => {
        expect(geometry().classifyInterval(start, end)).toBe(expected)
    })

    test.each([
        [-1, 0], [4, 4], [5, 4], [NaN, 4], [0, NaN],
        [0, Infinity], [-Infinity, 0], ['0', 45],
    ])('distingue l’intervalle invalide [%s, %s[', (start, end) => {
        expect(geometry().classifyInterval(start, end)).toBe('invalid')
    })
})
