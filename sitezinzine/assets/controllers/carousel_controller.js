import { Controller } from '@hotwired/stimulus'
import Glide from '@glidejs/glide'

export default class extends Controller {
  connect() {
    if (!this.element.classList.contains('glide')) {
      return
    }

    if (this.glide) {
      return
    }

    const liveSlide = this.element.querySelector('.glide__slide--live')
    const slides = Array.from(this.element.querySelectorAll('.glide__slide'))

    const liveIndex = liveSlide ? slides.indexOf(liveSlide) : -1
    const fallbackIndex = parseInt(this.element.dataset.carouselStartIndex || '0', 10)

    const startIndex = liveIndex >= 0
      ? liveIndex
      : (Number.isNaN(fallbackIndex) ? 0 : fallbackIndex)

    this.glide = new Glide(this.element, {
      type: 'slider',
      startAt: startIndex,
      focusAt: 'center',
      gap: 28,
      perView: 4,
      animationDuration: 600,
      autoplay: false,
      hoverpause: true,
      bound: true,
      rewind: false,

      breakpoints: {
        1400: { perView: 3 },
        1024: { perView: 2 },
        768: { perView: 1, focusAt: 'center', peek: { before: 24, after: 24 }, gap: 18 },
        480: { perView: 1, focusAt: 'center', peek: { before: 12, after: 12 }, gap: 14 }
      }
    })

    this.glide.mount()
  }

  disconnect() {
    if (this.glide) {
      this.glide.destroy()
      this.glide = null
    }
  }
}