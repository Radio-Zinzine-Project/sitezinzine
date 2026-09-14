import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['categorie', 'editeur', 'duree', 'users', 'usersField']

  static values = {
    usersUrl: String,
    emissionId: Number
  }

  connect() {
    this.abortController = null

    this.syncCategoryDefaults()
  }

  disconnect() {
    this.abortPendingRequest()
  }

  async categoryChanged() {
    this.syncCategoryDefaults()
    await this.refreshUsers()
  }

  syncCategoryDefaults() {
    if (!this.hasCategorieTarget) {
      return
    }

    const selectedOption = this.categorieTarget.selectedOptions[0]

    if (!selectedOption) {
      return
    }

    const editeurId = selectedOption.dataset.editeurId || ''
    const duree = selectedOption.dataset.duree || ''

    if (this.hasEditeurTarget && editeurId !== '') {
      this.editeurTarget.value = editeurId
      this.dispatchNativeChange(this.editeurTarget)
    }

    if (this.hasDureeTarget && duree !== '') {
      this.dureeTarget.value = duree
      this.dispatchNativeChange(this.dureeTarget)
    }
  }

  async refreshUsers() {
    if (
      !this.hasCategorieTarget
      || !this.hasUsersTarget
      || !this.hasUsersUrlValue
    ) {
      return
    }

    const categoryId = this.categorieTarget.value

    if (!categoryId) {
      this.usersTarget.replaceChildren()
      return
    }

    this.abortPendingRequest()

    this.abortController = new AbortController()

    const url = this.buildUsersUrl(categoryId)

    try {
      const response = await fetch(url, {
        method: 'GET',
        headers: {
          Accept: 'application/json'
        },
        credentials: 'same-origin',
        signal: this.abortController.signal
      })

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }

      const data = await response.json()

      this.populateUsers(data.users ?? [])
    } catch (error) {
      if (error.name === 'AbortError') {
        return
      }

      console.error(
        'Impossible de mettre à jour les utilisateurs de l’émission.',
        error
      )
    } finally {
      this.abortController = null
    }
  }

  buildUsersUrl(categoryId) {
    const path = this.usersUrlValue.replace(
      /\/0$/,
      `/${encodeURIComponent(categoryId)}`
    )

    const url = new URL(path, window.location.origin)

    if (this.hasEmissionIdValue) {
      url.searchParams.set(
        'emissionId',
        this.emissionIdValue
      )
    }

    return url.toString()
  }

  populateUsers(users) {
    const select = this.usersTarget

    select.replaceChildren()

    const groups = new Map()

    for (const user of users) {
      const groupLabel = user.group || ''

      if (groupLabel !== '') {
        let group = groups.get(groupLabel)

        if (!group) {
          group = document.createElement('optgroup')
          group.label = groupLabel

          groups.set(groupLabel, group)
          select.appendChild(group)
        }

        group.appendChild(
          this.createUserOption(user)
        )

        continue
      }

      select.appendChild(
        this.createUserOption(user)
      )
    }

    this.dispatchNativeChange(select)
  }

  createUserOption(user) {
    const option = document.createElement('option')

    option.value = String(user.id)
    option.textContent = user.label
    option.selected = Boolean(user.selected)

    if (user.status) {
      option.dataset.status = user.status
    }

    return option
  }

  abortPendingRequest() {
    if (!this.abortController) {
      return
    }

    this.abortController.abort()
    this.abortController = null
  }

  dispatchNativeChange(element) {
    element.dispatchEvent(
      new Event('change', { bubbles: true })
    )
  }
}