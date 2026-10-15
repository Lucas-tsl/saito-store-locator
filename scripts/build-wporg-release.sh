#!/usr/bin/env bash
# Assemble et vérifie le zip de soumission WordPress.org pour Saito Store Locator —
# même logique que deploy-local.sh (rsync + .distignore), avec en plus une
# passe de vérifications propres à une soumission publique (Text Domain,
# absence de fichiers de dev, structure du zip).
#
# Usage : ./scripts/build-wporg-release.sh [--copy-to DOSSIER] [--skip-plugin-check]
#   --copy-to DOSSIER     Copie le zip fini dans ce dossier une fois vérifié
#                         (supprime les anciens saito-store-locator-*.zip qui s'y trouvent).
#   --skip-plugin-check   Ignore l'étape wp plugin check (nécessite le
#                         conteneur navi_wp_cli de la stack docker-compose
#                         du plugin compagnon navi-wordpress, voir
#                         deploy-local.sh) — utile si cette stack n'est pas
#                         lancée.
#
# Produit : build/saito-store-locator-<version>.zip (dossier build/ nettoyé avant
# chaque exécution, jamais versionné — voir .gitignore).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$REPO_ROOT"

COPY_TO=""
RUN_PLUGIN_CHECK=1
while [ $# -gt 0 ]; do
    case "$1" in
        --copy-to)
            COPY_TO="${2:-}"
            shift 2
            ;;
        --skip-plugin-check)
            RUN_PLUGIN_CHECK=0
            shift
            ;;
        *)
            echo "Argument inconnu : $1" >&2
            exit 1
            ;;
    esac
done

VERSION=$(grep -m1 "^ \* Version:" saito-store-locator.php | sed 's/.*Version: *//' | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
    echo "Erreur : impossible de lire la version depuis saito-store-locator.php" >&2
    exit 1
fi

TEXT_DOMAIN=$(grep -m1 "^ \* Text Domain:" saito-store-locator.php | sed 's/.*Text Domain: *//' | tr -d '[:space:]')
if [ "$TEXT_DOMAIN" != "saito-store-locator" ]; then
    echo "Erreur : Text Domain (saito-store-locator.php) vaut '$TEXT_DOMAIN', attendu 'saito-store-locator'." >&2
    exit 1
fi

echo "==> Version détectée : $VERSION"

rm -rf build
mkdir -p "build/saito-store-locator"
rsync -a --exclude-from=".distignore" ./ "build/saito-store-locator/"

echo "==> Vérification : aucun fichier de dev dans le paquet assemblé"
LEAKED=$(find build/saito-store-locator -maxdepth 1 \( -name "node_modules" -o -name ".git" -o -name "composer.json" -o -name "package.json" -o -name "phpcs.xml.dist" -o -name "scripts" -o -name "README.md" -o -name "tests" -o -name "tools" \))
if [ -n "$LEAKED" ]; then
    echo "Erreur : fichiers de dev présents dans le paquet, .distignore incomplet ou obsolète :" >&2
    echo "$LEAKED" >&2
    exit 1
fi

ZIP_PATH="build/saito-store-locator-${VERSION}.zip"
echo "==> Construction de $ZIP_PATH"
(
    cd build
    if command -v zip >/dev/null 2>&1; then
        zip -rq "saito-store-locator-${VERSION}.zip" saito-store-locator
    else
        # zip absent (courant sur certaines images minimales) : repli sur
        # le module zipfile de Python, présent partout où PHP/WP tournent déjà.
        python3 - "$VERSION" <<'PYEOF'
import os
import sys
import zipfile

version = sys.argv[1]
zip_path = f"saito-store-locator-{version}.zip"
src_dir = "saito-store-locator"

with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(src_dir):
        for f in files:
            full = os.path.join(root, f)
            zf.write(full, os.path.relpath(full, "."))
PYEOF
    fi
)

echo "==> Vérification de la structure du zip"
python3 - "$ZIP_PATH" "$VERSION" <<'PYEOF'
import sys
import zipfile

zip_path, version = sys.argv[1], sys.argv[2]
zf = zipfile.ZipFile(zip_path)
names = zf.namelist()

top = sorted(set(n.split('/')[0] for n in names if n.strip()))
assert top == ['saito-store-locator'], f"Dossier racine du zip incorrect : {top} (attendu ['saito-store-locator'])"

for required in (
    'saito-store-locator/uninstall.php',
    'saito-store-locator/assets/vendor/leaflet/leaflet.js',
    'saito-store-locator/assets/vendor/leaflet/leaflet.css',
    'saito-store-locator/assets/vendor/leaflet.markercluster/leaflet.markercluster.js',
    'saito-store-locator/assets/data/communes.min.json',
):
    assert required in names, f"Fichier manquant dans le zip : {required} (.distignore trop large ?)"

data = zf.read('saito-store-locator/saito-store-locator.php').decode('utf-8')
assert f"Version: {version}" in data, "Version incohérente entre saito-store-locator.php et le nom du zip"
assert "Text Domain: saito-store-locator" in data, "Text Domain incorrect dans le zip"

print(f"OK : {len(names)} fichiers, dossier racine 'saito-store-locator/', Text Domain aligné, version {version}.")
PYEOF

if [ "$RUN_PLUGIN_CHECK" -eq 1 ]; then
    if docker exec navi_wp_cli true 2>/dev/null; then
        echo "==> Déploiement dans l'instance de dev locale pour wp plugin check"
        docker exec navi_wp_web rm -rf /var/www/html/wp-content/plugins/saito-store-locator
        docker cp "build/saito-store-locator/." navi_wp_web:/var/www/html/wp-content/plugins/saito-store-locator
        docker exec navi_wp_web chown -R www-data:www-data /var/www/html/wp-content/plugins/saito-store-locator
        docker exec navi_wp_cli wp plugin activate saito-store-locator --path=/var/www/html >/dev/null 2>&1 || true
        echo "==> wp plugin check saito-store-locator"
        docker exec navi_wp_cli wp plugin check saito-store-locator --path=/var/www/html
    else
        echo "==> Conteneur navi_wp_cli inaccessible, wp plugin check ignoré (lancer la stack docker-compose de navi-wordpress pour l'exécuter)."
    fi
fi

if [ -n "$COPY_TO" ]; then
    echo "==> Copie vers $COPY_TO"
    mkdir -p "$COPY_TO"
    rm -f "$COPY_TO"/saito-store-locator-*.zip
    cp "$ZIP_PATH" "$COPY_TO/"
    echo "OK : $COPY_TO/saito-store-locator-${VERSION}.zip"
fi

echo "==> Terminé : $ZIP_PATH"
