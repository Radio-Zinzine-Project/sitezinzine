import { describe, expect, test, vi } from 'vitest'

import {
    askRebroadcastStrategy,
    askRestoreRebroadcastStrategy
} from '../../../../assets/controllers/grid/rebroadcastModal'

import {
    element,
    jsonResponse,
    flushDom
} from '../../support/dom'

describe('Choix des rediffusions liées', () => {
    test('une rediffusion n’ouvre pas le choix réservé à la première diffusion', async () => {
        expect(
            await askRebroadcastStrategy({
                selectedPostit: element({
                    broadcastRank: '2'
                })
            })
        ).toEqual({
            strategy: 'keep',
            targets: []
        })

        expect(
            document.querySelector('[role="dialog"]')
        ).toBeNull()
    })

    test.each([
        [
            'keep',
            'Laisser les rediffusions à leurs horaires actuels'
        ],
        [
            'move',
            'Déplacer aussi les rediffusions d’une semaine'
        ],
        [
            'cancel',
            'Annuler les rediffusions liées'
        ],
    ])(
        'un déplacement d’une semaine propose %s avec un libellé explicite',
        async (choice, label) => {
            const result = askRebroadcastStrategy(
                {
                    selectedPostit: element({
                        broadcastRank: '1',
                    }),
                },
                'week'
            )

            const button = document.querySelector(
                `[data-choice="${choice}"]`
            )

            expect(button).not.toBeNull()
            expect(button.textContent.trim()).toBe(label)

            button.click()

            expect(await result).toEqual({
                strategy: choice,
                targets: [],
            })

            expect(
                document.querySelector('[role="dialog"]')
            ).toBeNull()
        }
    )

    test('un déplacement d’une semaine ne propose que les actions pertinentes', async () => {
        const result = askRebroadcastStrategy(
            {
                selectedPostit: element({
                    broadcastRank: '1',
                }),
            },
            'week'
        )

        expect(
            document.querySelector(
                '[data-choice="keep"]'
            )
        ).not.toBeNull()

        expect(
            document.querySelector(
                '[data-choice="move"]'
            )
        ).not.toBeNull()

        expect(
            document.querySelector(
                '[data-choice="cancel"]'
            )
        ).not.toBeNull()

        expect(
            document.querySelector(
                '[data-choice="custom"]'
            )
        ).toBeNull()

        expect(
            document.querySelector(
                '[data-choice="dismiss"]'
            )
        ).not.toBeNull()

        document
            .querySelector('[data-choice="dismiss"]')
            .click()

        expect(await result).toEqual({
            strategy: 'dismiss',
            targets: [],
        })
    })

    test('un déplacement personnalisé propose les quatre stratégies avec des libellés explicites', async () => {
        const result = askRebroadcastStrategy(
            {
                selectedPostit: element({
                    broadcastRank: '1',
                }),
            },
            'custom'
        )

        expect(
            document
                .querySelector('[data-choice="keep"]')
                .textContent
                .trim()
        ).toBe(
            'Laisser les rediffusions à leurs horaires actuels'
        )

        expect(
            document
                .querySelector('[data-choice="move"]')
                .textContent
                .trim()
        ).toBe(
            'Appliquer le même décalage aux rediffusions'
        )

        expect(
            document
                .querySelector('[data-choice="custom"]')
                .textContent
                .trim()
        ).toBe(
            'Choisir individuellement leurs nouveaux horaires'
        )

        expect(
            document
                .querySelector('[data-choice="cancel"]')
                .textContent
                .trim()
        ).toBe(
            'Annuler les rediffusions liées'
        )

        document
            .querySelector('[data-choice="dismiss"]')
            .click()

        expect(await result).toEqual({
            strategy: 'dismiss',
            targets: [],
        })
    })

    test('une annulation ne propose que de conserver ou annuler les rediffusions', async () => {
        const result = askRebroadcastStrategy(
            {
                selectedPostit: element({
                    broadcastRank: '1',
                }),
            },
            'cancel'
        )

        expect(
            document
                .querySelector('[data-choice="keep"]')
                .textContent
                .trim()
        ).toBe(
            'Conserver les rediffusions prévues'
        )

        expect(
            document
                .querySelector('[data-choice="cancel"]')
                .textContent
                .trim()
        ).toBe(
            'Annuler aussi les rediffusions'
        )

        expect(
            document.querySelector(
                '[data-choice="move"]'
            )
        ).toBeNull()

        expect(
            document.querySelector(
                '[data-choice="custom"]'
            )
        ).toBeNull()

        expect(
            document
                .querySelector('[data-choice="dismiss"]')
                .textContent
                .trim()
        ).toBe('Retour')

        document
            .querySelector('[data-choice="dismiss"]')
            .click()

        expect(await result).toEqual({
            strategy: 'dismiss',
            targets: [],
        })
    })

    test('Escape abandonne l’action sans choisir de stratégie', async () => {
        const remove = vi.spyOn(
            document,
            'removeEventListener'
        )

        const result = askRebroadcastStrategy(
            {
                selectedPostit: element({
                    broadcastRank: '1',
                }),
            },
            'week'
        )

        document.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Escape',
            })
        )

        expect(await result).toEqual({
            strategy: 'dismiss',
            targets: [],
        })

        expect(remove).toHaveBeenCalledWith(
            'keydown',
            expect.any(Function)
        )

        expect(
            document.querySelector('[role="dialog"]')
        ).toBeNull()
    })

    test('cliquer hors de la modale abandonne également l’action', async () => {
        const result = askRebroadcastStrategy(
            {
                selectedPostit: element({
                    broadcastRank: '1',
                }),
            },
            'week'
        )

        document
            .querySelector(
                '.rebroadcast-modal-overlay'
            )
            .click()

        expect(await result).toEqual({
            strategy: 'dismiss',
            targets: [],
        })

        expect(
            document.querySelector('[role="dialog"]')
        ).toBeNull()
    })

    test('les cibles personnalisées proviennent des slots liés à la date d’origine', async () => {
        fetch.mockResolvedValue(
            jsonResponse({
                items: [
                    {
                        slotId: 13,
                        startsAt:
                            '2026-10-08 15:00:00',
                        label: '<b>Rediff</b>',
                    },
                ],
            })
        )

        const result = askRebroadcastStrategy(
            {
                selectedPostit: element({
                    slotId: '12',
                    startsAt: 'nouveau',
                    originalStartsAt:
                        '2026-10-06 14:00:00',
                }),
            },
            'custom'
        )

        document
            .querySelector('[data-choice="custom"]')
            .click()

        await flushDom()

        const url = new URL(
            fetch.mock.calls[0][0],
            'http://localhost'
        )

        expect(
            url.searchParams.get('startsAt')
        ).toBe(
            '2026-10-06 14:00:00'
        )

        expect(
            document.querySelector(
                '.rebroadcast-item__label b'
            )
        ).toBeNull()

        document.querySelector(
            '[data-rebroadcast-date]'
        ).value = '2026-10-09'

        document.querySelector(
            '[data-rebroadcast-time]'
        ).value = '16:15'

        document.querySelector(
            '.rebroadcast-modal__confirm'
        ).click()

        expect(await result).toEqual({
            strategy: 'custom',
            targets: [
                {
                    slotId: '13',
                    newDate: '2026-10-09',
                    newTime: '16:15',
                },
            ],
        })
    })

    test('un échec de chargement permet de revenir sans choisir de stratégie', async () => {
        fetch.mockRejectedValue(
            new Error('Hors ligne')
        )

        const result = askRebroadcastStrategy(
            {
                selectedPostit: element({
                    slotId: '12',
                    startsAt: 'date',
                }),
            },
            'custom'
        )

        document
            .querySelector('[data-choice="custom"]')
            .click()

        await flushDom()

        expect(
            document.querySelector(
                '.rebroadcast-custom-zone'
            ).textContent
        ).toContain(
            'Impossible de charger'
        )

        document
            .querySelector('[data-choice="dismiss"]')
            .click()

        expect(await result).toEqual({
            strategy: 'dismiss',
            targets: [],
        })
    })

    test('restaurer uniquement le créneau courant ne restaure pas les rediffusions', async () => {
        const result =
            askRestoreRebroadcastStrategy({
                selectedPostit: element()
            })

        const button = document.querySelector(
            '[data-choice="only-current"]'
        )

        expect(button).not.toBeNull()

        button.click()

        expect(await result).toEqual({
            restoreLinkedRebroadcasts: false,
            dismissed: false
        })

        expect(
            document.querySelector('[role="dialog"]')
        ).toBeNull()
    })

    test('restaurer aussi les rediffusions retourne le choix lié', async () => {
        const result =
            askRestoreRebroadcastStrategy({
                selectedPostit: element()
            })

        const button = document.querySelector(
            '[data-choice="with-linked"]'
        )

        expect(button).not.toBeNull()

        button.click()

        expect(await result).toEqual({
            restoreLinkedRebroadcasts: true,
            dismissed: false
        })

        expect(
            document.querySelector('[role="dialog"]')
        ).toBeNull()
    })

    test('Retour abandonne la restauration sans choisir uniquement le créneau courant', async () => {
        const result =
            askRestoreRebroadcastStrategy({
                selectedPostit: element()
            })

        const overlay = document.querySelector(
            '.rebroadcast-modal-overlay'
        )

        expect(overlay).not.toBeNull()

        const returnButton = overlay.querySelector(
            '[data-choice="dismiss"]'
        )

        expect(returnButton).not.toBeNull()

        returnButton.click()

        expect(await result).toEqual({
            restoreLinkedRebroadcasts: false,
            dismissed: true
        })

        expect(
            document.querySelector('[role="dialog"]')
        ).toBeNull()
    })

    test('Échap abandonne la restauration', async () => {
        const result =
            askRestoreRebroadcastStrategy({
                selectedPostit: element()
            })

        document.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Escape'
            })
        )

        expect(await result).toEqual({
            restoreLinkedRebroadcasts: false,
            dismissed: true
        })

        expect(
            document.querySelector('[role="dialog"]')
        ).toBeNull()
    })

    test('cliquer en dehors de la modale abandonne la restauration', async () => {
        const result =
            askRestoreRebroadcastStrategy({
                selectedPostit: element()
            })

        const overlay = document.querySelector(
            '.rebroadcast-modal-overlay'
        )

        expect(overlay).not.toBeNull()

        overlay.click()

        expect(await result).toEqual({
            restoreLinkedRebroadcasts: false,
            dismissed: true
        })

        expect(
            document.querySelector('[role="dialog"]')
        ).toBeNull()
    })
})