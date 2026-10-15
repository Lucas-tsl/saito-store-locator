#!/usr/bin/env python3
"""Génère assets/data/communes.min.json à partir de la base officielle des codes postaux.

Source : « Base officielle des codes postaux » (La Poste, data.gouv.fr), au format JSON
avec les colonnes nom_commune_complet, code_postal, ligne_5, latitude, longitude.

Le fichier produit est un tableau de tuples compacts :
    [nom_commune, code_postal (5 chiffres), ligne_5, latitude, longitude]

Les communes sans coordonnées sont complétées par CORRECTIONS ou ignorées
(il s'agit essentiellement de collectivités d'outre-mer du Pacifique).

Usage : python3 tools/build-communes.py chemin/vers/communes.json [sortie.json]
"""
import json
import sys
from pathlib import Path

# Coordonnées manquantes dans la source, complétées à la main (centre de la commune).
CORRECTIONS = {
    ("Culey", "55000"): (48.7553, 5.2669),
    ("Bihorel", "76420"): (49.4554, 1.1222),
}


def coord(value):
    try:
        return round(float(str(value).replace(",", ".")), 4)
    except (TypeError, ValueError):
        return None


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    src = Path(sys.argv[1])
    out = Path(sys.argv[2]) if len(sys.argv) > 2 else Path(__file__).resolve().parent.parent / "assets/data/communes.min.json"

    rows = json.loads(src.read_text(encoding="utf-8"))
    seen = set()
    result = []
    skipped = 0
    for row in rows:
        name = (row.get("nom_commune_complet") or "").strip()
        cp = (row.get("code_postal") or "").strip()
        if not name or not cp:
            skipped += 1
            continue
        cp = cp.zfill(5)
        l5 = (row.get("ligne_5") or "").strip()
        lat, lng = coord(row.get("latitude")), coord(row.get("longitude"))
        if lat is None or lng is None:
            fix = CORRECTIONS.get((name, cp))
            if not fix:
                skipped += 1
                continue
            lat, lng = fix
        key = (name, cp, l5)
        if key in seen:
            continue
        seen.add(key)
        result.append([name, cp, l5, lat, lng])

    result.sort(key=lambda r: (r[1], r[0], r[2]))
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(result, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"{len(result)} entrées écrites dans {out} ({out.stat().st_size / 1e6:.2f} Mo), {skipped} ignorées")


if __name__ == "__main__":
    main()
