#!/usr/bin/env bash
set -euo pipefail

plugin_slug="nolantis-gestion"
root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_file="${root_dir}/plugin/${plugin_slug}/${plugin_slug}-plugin.php"

version="${1:-}"

if [[ -z "${version}" ]]; then
    version="$(grep -E '^[[:space:]]*\* Version:' "${plugin_file}" | sed -E 's/.*Version:[[:space:]]*//')"
fi

if [[ -z "${version}" ]]; then
    echo "No se ha podido detectar la version del plugin." >&2
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

if [[ -d "${root_dir}/vendor" ]]; then
    mkdir -p "${stage_dir}/${plugin_slug}/vendor"
    cp -R "${root_dir}/vendor/." "${stage_dir}/${plugin_slug}/vendor/"
fi

(
    cd "${stage_dir}"
    zip -rq "${tmp_output_file}" "${plugin_slug}" \
        -x '*/.DS_Store' \
        -x '*/__MACOSX/*' \
        -x '*/.git/*'
)

cp "${tmp_output_file}" "${output_file}"

echo "${output_file}"
