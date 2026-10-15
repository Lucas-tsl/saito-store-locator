# Saito Store Locator

Plugin WordPress : carte des points de vente, avec recherche par ville, code
postal, nom de magasin ou position, fiches magasin indexables, import CSV et
synchronisation Google Sheets.

Quatrième plugin de la famille Saito, après
[Saito Navi](https://wordpress.org/plugins/saito-navi/),
[Saito FAQ](https://wordpress.org/plugins/saito-faq/) et
[Saito Calendar Event](https://github.com/Lucas-tsl/saito-calendar-event).

## Fonctionnalités

- **Recherche** par ville ou code postal (communes françaises intégrées, codes
  postaux saisis sans le zéro initial), par nom de magasin, ou « Autour de
  moi » (la position n'est jamais demandée au chargement de la page).
  Combobox ARIA navigable au clavier.
- **Carte** plein cadre avec la liste superposée (repliable), marqueurs
  regroupés, survol lié liste ↔ carte, « Rechercher dans cette zone » et
  « Voir plus de points de vente ». Mobile : bascule Carte / Liste, barre de
  recherche toujours visible, déplacement à deux doigts.
- **Fiches magasin** : distance, état « Ouvert / Fermé » calculé dans le
  fuseau horaire du site, téléphone, horaires de la semaine, services, site
  web, menu « J'Y VAIS » (Google Maps, Apple Plans, Waze).
- **Référencement local** : une URL par magasin (`?magasin=…`) avec titre,
  description, URL canonique et données structurées `schema.org/Store`,
  ajoutée au plan du site (WordPress ou Yoast SEO) ; compatible Yoast SEO et
  Rank Math.
- **Administration** : import CSV avec rapport avant mise en ligne,
  synchronisation Google Sheets (manuelle ou quotidienne, avec garde-fou et
  alerte e-mail), géocodage des adresses françaises, 10 sauvegardes
  restaurables, export CSV.
- **Thèmes** clair (par défaut) et sombre, uniquement en noir, blanc et gris ;
  animations désactivées si le visiteur préfère moins de mouvement.

## Utilisation

| Shortcode | Effet |
|---|---|
| `[saito_store_locator]` | Recherche, carte et liste des magasins les plus proches |
| `[saito_store_locator results="6"]` | 6 résultats au lieu du réglage par défaut |
| `[saito_store_locator theme="dark"]` | Thème sombre sur cette page |
| `[saito_store_locator width="full"]` | Toute la largeur de la page (`width="content"` : largeur du texte ; par défaut : largeur « large » du thème). `largeur="pleine"` / `largeur="contenu"` fonctionnent aussi |
| `[saito_store_locator_list]` | Liste HTML complète des magasins, par pays puis par ville |

### Colonnes du CSV

Obligatoires : `name`, `latitude`, `longitude`. Reconnues : `id_store`,
`active` (1/oui), `address1`, `address2`, `postcode`, `city`, `country`,
`phone`, `website`, `enseigne`, `services` (séparés par des virgules),
`horaires`, `icone` (`signature` = marqueur mis en avant, `rouge` = marqueur
rouge). Les en-têtes français (`nom`, `ville`, `code postal`, `telephone`…)
sont acceptés. Exemple complet : [`tests/fixtures/demo-stores.csv`](tests/fixtures/demo-stores.csv)
(44 magasins fictifs).

Horaires en texte libre, un segment par groupe de jours (`;`, `|` ou retour à
la ligne) : `Lundi-Samedi 9h30-19h30 ; Dimanche fermé`,
`Lun au ven 9h-12h 14h-19h | Sam 9h-18h`, `Tous les jours 10h-20h`. Un texte
non reconnu est affiché tel quel et signalé dans le rapport d'import.

Pour un magasin français sans latitude/longitude, la position est calculée
depuis l'adresse par le service public de géocodage de l'IGN (50 adresses au
plus par import).

## Services externes

- Tuiles **OpenStreetMap** par défaut, ou **MapTiler** si une clé est
  renseignée (Paramètres). La clé MapTiler est forcément visible dans les
  pages : limitez-la à votre domaine (Account > API keys > Allowed HTTP
  origins).
- **Géoplateforme IGN** (géocodage des adresses françaises à l'import).
- **Google Sheets** (synchronisation, si une feuille est configurée).

Détail et liens vers les conditions d'utilisation : section « External
services » de [`readme.txt`](readme.txt).

## Structure

```
saito-store-locator.php    Point d'entrée, constantes, activation
uninstall.php              Suppression des réglages, de la tâche planifiée et des données
includes/settings.php      Réglages (option saito_sl_settings)
includes/storage.php       Fichier des magasins (uploads/saito-store-locator/), sauvegardes
includes/import.php        Lecture et validation du CSV, géocodage
includes/sync.php          Synchronisation Google Sheets (manuelle et quotidienne)
includes/admin.php         Pages d'administration (actions protégées par nonce + droits)
includes/frontend.php      Shortcodes, ressources, fiche rendue côté serveur, JSON-LD
includes/store.php         Enseigne, casse des noms, horaires, téléphone, identifiants d'URL
includes/seo.php           Fiches indexables : titre, description, canonique, plan du site
assets/js/storelocator.js  Carte, recherche, géolocalisation (traductions via wp-i18n)
assets/data/communes.min.json  Communes françaises (1,7 Mo, 0,6 Mo compressé)
assets/vendor/             Leaflet 1.9.4 et Leaflet.markercluster 1.5.3 (builds officiels)
tools/build-communes.py    Régénère communes.min.json depuis la base officielle des codes postaux
```

## Développement

```bash
composer install && vendor/bin/phpcs          # WordPress Coding Standards
for t in tests/test-*.php; do php "$t"; done  # tests sans WordPress
npm install && npm run lint                   # ESLint + Stylelint
./scripts/build-wporg-release.sh              # zip WordPress.org (+ Plugin Check si la stack locale tourne)
```

Tests d'intégration, sur un site de test uniquement (le test de
synchronisation remplace la liste des magasins) :

```bash
SAITO_SL_TESTS=1 wp eval-file tests/php/run.php
npm i playwright && node tests/e2e/storelocator.mjs "https://site-de-test/page-store-locator/"
```

Le test navigateur suppose les magasins de démonstration importés.

## Licence

GPLv2 ou ultérieure. Leaflet (BSD 2-Clause) et Leaflet.markercluster (MIT)
sont embarqués sans modification. `communes.min.json` est dérivé de la
« Base officielle des codes postaux » de La Poste (Licence Ouverte 2.0).
