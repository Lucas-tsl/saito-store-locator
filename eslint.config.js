const js = require("@eslint/js");

module.exports = [
  {
    // Bibliothèques tierces embarquées (minifiées, maintenues en amont).
    ignores: ["assets/vendor/**"],
  },
  js.configs.recommended,
  {
    files: ["assets/js/**/*.js"],
    languageOptions: {
      ecmaVersion: 2021,
      sourceType: "script",
      globals: {
        window: "readonly",
        document: "readonly",
        navigator: "readonly",
        jQuery: "readonly",
        wp: "readonly",
        fetch: "readonly",
        FormData: "readonly",
        URLSearchParams: "readonly",
        URL: "readonly",
        FileReader: "readonly",
        Option: "readonly",
        Promise: "readonly",
        setTimeout: "readonly",
        clearTimeout: "readonly",
        requestAnimationFrame: "readonly",
        encodeURIComponent: "readonly",
        L: "readonly",
        Intl: "readonly",
        CSS: "readonly",
        IntersectionObserver: "readonly",
        matchMedia: "readonly",
      },
    },
  },
];
