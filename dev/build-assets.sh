#!/usr/bin/env bash
#
# Bundle the TypeScript client-side Pal export (resources/ts/) into
# resources/dist/palexport.js - the single self-contained script (WASM
# embedded) served to browsers by the plugin's asset route.
#
# The dist bundle is COMMITTED so installed panels never need node/npm;
# re-run this after changing anything under resources/ts/.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "${REPO_DIR}"

# node:module is only touched by the emscripten glue's node branch - browsers
# never reach it, so leaving the import unresolved (external) is safe
npx --yes esbuild@0.25.0 resources/ts/main.ts \
    --bundle \
    --format=iife \
    --target=es2022 \
    --external:node:module \
    --outfile=resources/dist/palexport.js

echo "built resources/dist/palexport.js"
