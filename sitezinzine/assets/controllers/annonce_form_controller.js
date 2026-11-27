import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["typeSelect", "autreTypeWrapper", "limitField"];

  connect() {
    this.toggleAutreType();

    this.limitFieldTargets.forEach((field) => {
      const max = this._getMax(field);
      if (!max) return;

      field.setAttribute("maxlength", String(max));

      // bind handler par champ (pour pouvoir remove proprement)
      const handler = (event) => this._enforceMaxLength(event);
      field._limitHandler = handler;

      this._ensureLimitMessage(field);
      field.addEventListener("input", handler);

      this._updateLimitMessage(field, max);
    });
  }

  disconnect() {
    this.limitFieldTargets.forEach((field) => {
      if (field._limitHandler) {
        field.removeEventListener("input", field._limitHandler);
        delete field._limitHandler;
      }
    });
  }

// === Autre type ===
static values = { autre: String };

toggleAutreType = () => {
  if (!this.hasTypeSelectTarget || !this.hasAutreTypeWrapperTarget) return;

  const autreValue = this.hasAutreValue ? this.autreValue : "__autre__";
  const value = (this.typeSelectTarget.value || "").trim();
  const shouldShow = value === autreValue;

  this.autreTypeWrapperTarget.classList.toggle("is-visible", shouldShow);

  if (!shouldShow) {
    const input = this.autreTypeWrapperTarget.querySelector("input, textarea, select");
    if (input) input.value = "";
  }
};


  // === Limites ===
  _enforceMaxLength(event) {
    const field = event.target;
    const max = this._getMax(field);
    if (!max) return;

    if (field.value.length > max) {
      field.value = field.value.slice(0, max);
    }
    this._updateLimitMessage(field, max);
  }

  _getMax(field) {
    const raw = field.dataset.maxlength;
    const max = raw ? parseInt(raw, 10) : NaN;
    return Number.isFinite(max) && max > 0 ? max : null;
  }

  _ensureLimitMessage(field) {
    // si on l'a déjà ajouté, il est juste après le champ
    const next = field.nextElementSibling;
    if (next && next.classList.contains("field-limit")) return next;

    const msg = document.createElement("div");
    msg.className = "field-limit";
    field.insertAdjacentElement("afterend", msg);
    return msg;
  }

  _updateLimitMessage(field, max) {
    const msg = this._ensureLimitMessage(field);
    const len = (field.value || "").length;
    msg.textContent = `${len}/${max}`;
  }
}
