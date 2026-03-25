#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# i18n-completeness.sh
#
# Verifies that every translation file (i18n/*.json, except en.json and
# qqq.json) contains all message keys present in the canonical English
# source (i18n/en.json).
#
# Requires: jq (https://jqlang.github.io/jq/)
#
# Exit codes:
#   0 — all translations are complete
#   1 — one or more translations are missing keys (details printed to stdout)
# ---------------------------------------------------------------------------

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
I18N_DIR="${SCRIPT_DIR}/../i18n"
EN_JSON="${I18N_DIR}/en.json"

if ! command -v jq &> /dev/null; then
    echo "ERROR: jq is required but not installed." >&2
    exit 1
fi

if [[ ! -f "${EN_JSON}" ]]; then
    echo "ERROR: canonical source not found: ${EN_JSON}" >&2
    exit 1
fi

# Extract user-visible message keys (skip @metadata and other @ keys).
en_keys=$(jq -r '[keys[] | select(startswith("@") | not)] | .[]' "${EN_JSON}")

failures=0

for f in "${I18N_DIR}"/*.json; do
    lang=$(basename "${f}" .json)

    # Skip the canonical source and the message-documentation file.
    if [[ "${lang}" == "en" || "${lang}" == "qqq" ]]; then
        continue
    fi

    lang_keys=$(jq -r '[keys[] | select(startswith("@") | not)] | .[]' "${f}")

    missing=()
    while IFS= read -r key; do
        if ! grep -qF "${key}" <<< "${lang_keys}"; then
            missing+=("${key}")
        fi
    done <<< "${en_keys}"

    if [[ ${#missing[@]} -gt 0 ]]; then
        echo "FAIL [${lang}]: missing ${#missing[@]} key(s):"
        for key in "${missing[@]}"; do
            echo "  - ${key}"
        done
        failures=$((failures + 1))
    fi
done

if [[ ${failures} -eq 0 ]]; then
    echo "OK: all translations are complete."
    exit 0
else
    echo ""
    echo "FAILED: ${failures} translation file(s) have missing keys."
    exit 1
fi
