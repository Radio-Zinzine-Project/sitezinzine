import { Controller } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"


export default class extends Controller {
    static values = {
        url: String
    }

    redirectWithReturnTo(event) {
        event.preventDefault()

        const currentUrl = window.location.pathname + window.location.search
        const target = `${this.urlValue}?returnTo=${encodeURIComponent(currentUrl)}`
        Turbo.visit(target)
    }

     confirmBeforeSubmit(event) {
    const confirmed = window.confirm("Êtes-vous sûr de vouloir supprimer cet élément ?");
    if (!confirmed) {
      event.preventDefault(); // annule la soumission du formulaire
    }
    // Sinon, la soumission continue normalement
  }
  
}
