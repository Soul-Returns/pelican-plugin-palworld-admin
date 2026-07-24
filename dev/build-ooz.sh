#!/usr/bin/env bash
#
# Rebuild resources/ts/ooz.mjs - the WASM build of the Oodle decompressor
# (github.com/zao/ooz, decompression-only) used by the client-side Pal export
# to read Palworld's Oodle-compressed (PlM) saves in the browser.
#
# Only needed when bumping the ooz revision; the artifact is committed.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

git clone --depth 1 --recurse-submodules --shallow-submodules https://github.com/zao/ooz "${WORK}/ooz"

docker run --rm -v "${WORK}/ooz:/src" -w /src emscripten/emsdk:latest emcc -O2 -I/src -I/src/simde \
    kraken.cpp bitknit.cpp lzna.cpp \
    -s EXPORTED_FUNCTIONS='["_Ooz_Decompress","_malloc","_free"]' \
    -s EXPORTED_RUNTIME_METHODS='["HEAPU8"]' \
    -s MODULARIZE=1 -s EXPORT_ES6=1 -s SINGLE_FILE=1 -s ALLOW_MEMORY_GROWTH=1 \
    -s ENVIRONMENT=web,worker,node \
    -o ooz.mjs

cp "${WORK}/ooz/ooz.mjs" "${REPO_DIR}/resources/ts/ooz.mjs"
echo "updated ${REPO_DIR}/resources/ts/ooz.mjs"
