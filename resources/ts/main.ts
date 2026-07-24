/**
 * Browser entry - bundled by dev/build-assets.sh (esbuild) into
 * resources/dist/palexport.js and served by the plugin's asset route.
 */

import { PalExport } from './palexport.ts';

declare global {
    interface Window {
        PalworldPalExport: typeof PalExport;
    }
}

window.PalworldPalExport = PalExport;
