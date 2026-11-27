import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["modal", "image"];
  static values = {
    src: String,
    alt: String
  };

  connect() {
    // état propre quand Turbo remet le DOM
    this._isOpen = false;
    this._previousOverflow = "";
  }

  disconnect() {
    // si on change de page pendant que c'est ouvert
    this._unlockScroll();
  }

  open(event) {
    // récupère les valeurs depuis le bouton cliqué
    const el = event?.currentTarget;
    const src = el?.dataset?.imageModalSrcValue || this.srcValue;
    const alt = el?.dataset?.imageModalAltValue || this.altValue || "";

    if (!src) return;

    this.imageTarget.src = src;
    this.imageTarget.alt = alt;

    this.modalTarget.classList.add("is-open");
    this.modalTarget.setAttribute("aria-hidden", "false");

    this._lockScroll();
    this._isOpen = true;
  }

  close() {
    if (!this._isOpen) return;

    this.modalTarget.classList.remove("is-open");
    this.modalTarget.setAttribute("aria-hidden", "true");

    // vider l'image évite un flash à la réouverture
    this.imageTarget.src = "";
    this.imageTarget.alt = "";

    this._unlockScroll();
    this._isOpen = false;
  }

  backdropClick(event) {
    // ferme seulement si on clique le backdrop, pas l'image/bouton
    if (event.target === this.modalTarget) {
      this.close();
    }
  }

  onKeydown(event) {
    if (event.key === "Escape") {
      this.close();
    }
  }

  _lockScroll() {
    // robuste : on restaure au close
    this._previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
  }

  _unlockScroll() {
    document.body.style.overflow = this._previousOverflow || "";
    this._previousOverflow = "";
  }
}
