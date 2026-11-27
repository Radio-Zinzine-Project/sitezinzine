import { Controller } from "@hotwired/stimulus";
import tinymce from "tinymce";

// Thème & icônes
import "tinymce/themes/silver";
import "tinymce/icons/default";

// Plugins
import "tinymce/plugins/advlist";
import "tinymce/plugins/autolink";
import "tinymce/plugins/lists";
import "tinymce/plugins/link";
import "tinymce/plugins/image";
import "tinymce/plugins/charmap";
import "tinymce/plugins/preview";
import "tinymce/plugins/anchor";
import "tinymce/plugins/pagebreak";
import "tinymce/plugins/searchreplace";
import "tinymce/plugins/wordcount";
import "tinymce/plugins/visualblocks";
import "tinymce/plugins/visualchars";
import "tinymce/plugins/code";
import "tinymce/plugins/fullscreen";
import "tinymce/plugins/insertdatetime";
import "tinymce/plugins/media";
import "tinymce/plugins/nonbreaking";
import "tinymce/plugins/save";
import "tinymce/plugins/table";
import "tinymce/plugins/directionality";
import "tinymce/plugins/emoticons";

export default class extends Controller {
  static values = {
    css: String,              // ✅ déjà utilisé (editable.css)
    richtextCss: String,      // ✅ nouveau (optionnel)
    bodyClass: String,        // ✅ nouveau (optionnel)
    uploadUrl: String         // ✅ nouveau (optionnel)
  };

connect() {
  this.reloadEditor = this.init.bind(this);
  this.element.addEventListener("tinymce:reload", this.reloadEditor);

  // ✅ Turbo : évite les états bizarres quand Turbo met en cache
  this.beforeCache = () => this._removeEditor();
  document.addEventListener("turbo:before-cache", this.beforeCache);

  // ✅ IMPORTANT : copie le contenu TinyMCE dans le <textarea> avant submit
  this.form = this.element.closest("form");
  this.onSubmit = () => {
    if (window.tinymce) {
      window.tinymce.triggerSave();
    }
  };
  if (this.form) {
    this.form.addEventListener("submit", this.onSubmit);
  }

  setTimeout(() => this.init(), 0);
}


disconnect() {
  // 🔴 IMPORTANT : retirer le listener submit
  if (this.form && this.onSubmit) {
    this.form.removeEventListener("submit", this.onSubmit);
  }

  this._removeEditor();
  this.element.removeEventListener("tinymce:reload", this.reloadEditor);
  document.removeEventListener("turbo:before-cache", this.beforeCache);
}


  _removeEditor() {
    const textarea = this.element.querySelector("textarea");
    if (!textarea) return;

    if (textarea.id) {
      const existing = tinymce.get(textarea.id);
      if (existing) tinymce.remove(existing);
    }
  }

  init() {
    const textarea = this.element.querySelector("textarea");
    if (!textarea) {
      console.warn("Aucun <textarea> trouvé pour TinyMCE.");
      return;
    }

    if (!textarea.id) {
      textarea.id = `tinymce-${Math.random().toString(36).substring(2, 9)}`;
    }

    // Supprime si déjà initialisé
    const existing = tinymce.get(textarea.id);
    if (existing) tinymce.remove(existing);

    // ✅ IMPORTANT : on garde ton comportement actuel PAR DÉFAUT
    const bodyClass = this.hasBodyClassValue ? this.bodyClassValue : "page-content";

    // ✅ IMPORTANT : on garde editable.css + fonts
    const contentCss = [
      this.cssValue,
      "https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;900&family=Montserrat+Alternates:wght@400;500;700;900&display=swap"
    ];

    // ✅ Si on est sur annonces, on ajoute le CSS richtext sans toucher aux éditables
    if (this.hasRichtextCssValue && this.richtextCssValue) {
      contentCss.splice(1, 0, this.richtextCssValue); // insère après editable.css
    }

    const uploadUrl = this.hasUploadUrlValue && this.uploadUrlValue
      ? this.uploadUrlValue
      : "/admin/tinymce/upload"; // ✅ ton URL actuelle par défaut

    tinymce.init({
      target: textarea,

      content_css: contentCss,
      content_style: `html { background: linear-gradient(#E4013A, #B81C61); }`,
      body_class: bodyClass,

      language: "fr_FR",
      language_url: "https://cdn.tiny.cloud/1/no-api-key/tinymce/6/langs/fr_FR.js",

      plugins: [
        "advlist","autolink","lists","link","image","charmap","preview",
        "anchor","pagebreak","searchreplace","wordcount","visualblocks",
        "visualchars","code","fullscreen","insertdatetime","media",
        "nonbreaking","save","table","directionality","emoticons"
      ],

      toolbar:
        "undo redo | bold italic underline | alignleft aligncenter alignright alignjustify | " +
        "outdent indent | bullist numlist | link image media | table | code preview fullscreen",

      relative_urls: false,
      remove_script_host: false,
      convert_urls: true,
      document_base_url: window.location.origin + "/",

      menubar: "file edit view insert format tools table help",
      height: 300,

      skin_url: "/build/skins/ui/oxide",
      base_url: "/build",
      suffix: ".min",
      license_key: "gpl",

      automatic_uploads: true,
      file_picker_types: "image",

      images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.withCredentials = true;
        xhr.open("POST", uploadUrl);

        xhr.onload = () => {
          if (xhr.status < 200 || xhr.status >= 300) {
            return reject("HTTP Error: " + xhr.status);
          }

          let json;
          try { json = JSON.parse(xhr.responseText); }
          catch { return reject("Invalid JSON: " + xhr.responseText); }

          if (!json.location) return reject("Invalid response: missing location");
          resolve(json.location);
        };

        xhr.onerror = () => reject("XHR Transport Error");

        const formData = new FormData();
        formData.append("file", blobInfo.blob(), blobInfo.filename());
        xhr.send(formData);
      }),

      setup: (editor) => {
        editor.on("change keyup", () => editor.save());
      }
    });
  }
}
