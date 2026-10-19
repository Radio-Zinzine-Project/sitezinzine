import { beforeEach, describe, expect, test, vi } from 'vitest'
import Glide from '@glidejs/glide'
import CarouselController from '../../../assets/controllers/carousel_controller'
import { mountController, flushDom } from '../support/dom'

vi.mock('@glidejs/glide', () => ({ default: vi.fn() }))
let slider
beforeEach(() => {
    slider = { mount: vi.fn(), destroy: vi.fn() }
    Glide.mockImplementation(function () { return slider })
})

describe('Intégration du carrousel, Glide simulé', () => {
    test('le passage en direct est prioritaire sur l’index de repli', async () => {
        await mountController('carousel', CarouselController, `<div class="glide" data-controller="carousel" data-carousel-start-index="0">
            <div class="glide__slide"></div><div class="glide__slide glide__slide--live"></div>
        </div>`)
        expect(Glide.mock.calls[0][1]).toMatchObject({ startAt: 1, autoplay: false, rewind: false })
        expect(slider.mount).toHaveBeenCalledOnce()
    })

    test('sans direct utilise le repli et détruit le slider à la déconnexion', async () => {
        const { root } = await mountController('carousel', CarouselController,
            '<div class="glide" data-controller="carousel" data-carousel-start-index="2"><div class="glide__slide"></div></div>')
        expect(Glide.mock.calls[0][1].startAt).toBe(2)
        root.remove(); await flushDom()
        expect(slider.destroy).toHaveBeenCalledOnce()
    })

    test('un élément sans classe glide n’instancie pas la bibliothèque', async () => {
        await mountController('carousel', CarouselController, '<div data-controller="carousel"></div>')
        expect(Glide).not.toHaveBeenCalled()
    })
})
