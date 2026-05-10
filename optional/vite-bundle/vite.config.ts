// Vite build config for a Drupal theme bundle.
//
// Output goes into the theme's dist/ directory so libraries.yml
// can reference it by relative path. When the host theme is
// renamed, update `outDir` below.
//
// `lib` mode produces a single ES module bundle suitable for
// loading via `<script type="module">`. If you need code-splitting
// (multiple lazy chunks), switch to non-lib mode and configure
// rollupOptions.input + manualChunks.

import { defineConfig } from "vite";

export default defineConfig({
  build: {
    outDir: "../../web/themes/custom/example_theme/dist",
    emptyOutDir: true,
    sourcemap: true,
    minify: "esbuild",
    target: "es2022",
    lib: {
      entry: "src/index.ts",
      name: "ExampleTheme",
      fileName: () => "index.js",
      formats: ["es"],
    },
    rollupOptions: {
      output: {
        // Inline assets — themes load this as a single library.
        // Switch to chunked output when bundle crosses ~500 KB
        // (the reference project added manualChunks at that point).
        inlineDynamicImports: true,
      },
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    host: "0.0.0.0",
  },
  test: {
    environment: "node",
    include: ["test/**/*.test.ts"],
  },
});
