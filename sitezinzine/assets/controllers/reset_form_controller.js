import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = { url: String };

  reset(event) {
    event.preventDefault();

    const url = this.hasUrlValue
      ? this.urlValue
      : window.location.pathname;

    window.location.assign(url);
  }
}