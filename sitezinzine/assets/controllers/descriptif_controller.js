import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["content", "button"];

  connect() {
    this.checkTruncation();
  }

  checkTruncation() {
    // Forcer un reflow pour mesurer précisément
    requestAnimationFrame(() => {
      const content = this.contentTarget;
      const isTruncated = content.scrollHeight > content.clientHeight + 1;


      if (isTruncated) {
        this.buttonTarget.hidden = false;
      } else {
        this.buttonTarget.hidden = true;
      }
    });
  }

  toggle() {
    this.contentTarget.classList.toggle("expanded");

    this.buttonTarget.textContent = this.contentTarget.classList.contains("expanded")
      ? "Réduire"
      : "Lire la suite";
  }
}
