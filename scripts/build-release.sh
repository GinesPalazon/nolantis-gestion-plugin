#!/usr/bin/env bash
set -euo pipefail

plugin_slug="nolantis-gestion"
root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_file="${root_dir}/plugin/${plugin_slug}/${plugin_slug}-plugin.php"

version="${1:-}"
plugin_version="$(grep -E '^[[:space:]]*\* Version:' "${plugin_file}" | sed -E 's/.*Version:[[:space:]]*//')"

if [[ -z "${version}" ]]; then
    version="${plugin_version}"
fi

if [[ -z "${version}" ]]; then
    echo "No se ha podido detectar la version del plugin." >&2
    exit 1
fi

if [[ "${version}" != "${plugin_version}" ]]; then
    echo "La version del tag/release (${version}) no coincide con la version declarada en el plugin (${plugin_version})." >&2
    echo "Actualiza el header Version y NOLANTIS_VERSION antes de publicar la release." >&2
    exit 1
fi

stage_dir="$(mktemp -d)"
output_file="${root_dir}/${plugin_slug}-${version}.zip"
tmp_output_file="${stage_dir}/${plugin_slug}-${version}.zip"

cleanup() {
    rm -rf "${stage_dir}"
}
trap cleanup EXIT

mkdir -p "${stage_dir}/${plugin_slug}"
cp -R "${root_dir}/plugin/${plugin_slug}/." "${stage_dir}/${plugin_slug}/"

# En GitHub Actions, "composer install" genera vendor/ en la raiz a partir de composer.lock:
# se usa esa copia, recien instalada, en lugar de la que hay dentro del plugin.
if [[ -d "${root_dir}/vendor" ]]; then
    rm -rf "${stage_dir}/${plugin_slug}/vendor"
    mkdir -p "${stage_dir}/${plugin_slug}/vendor"
    cp -R "${root_dir}/vendor/." "${stage_dir}/${plugin_slug}/vendor/"
fi

if [[ ! -f "${stage_dir}/${plugin_slug}/vendor/autoload.php" ]]; then
    echo "Falta vendor/autoload.php: el actualizador del plugin no funcionaria." >&2
    exit 1
fi

# readme.txt de la raiz del repositorio (el actualizador lo lee de ahi para "Ver detalles").
cp "${root_dir}/readme.txt" "${stage_dir}/${plugin_slug}/readme.txt"

(
    cd "${stage_dir}"
    zip -rq "${tmp_output_file}" "${plugin_slug}" \
        -x '*/.DS_Store' \
        -x '*/__MACOSX/*' \
        -x '*/.git/*' \
        -x '*/.gitignore' \
        -x '*/Thumbs.db'
)

cp "${tmp_output_file}" "${output_file}"

echo "${output_file}"
