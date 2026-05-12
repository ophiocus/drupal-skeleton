# Optional Vite + TypeScript + Vitest bundle

Drop this into a theme that wants a modern JS pipeline. It is
**not** active by default — themes without JS (or themes happy
with raw `*.js` in `js/`) shouldn't pay any of the cost.

## Installation

```bash
# From the skeleton root, with example_theme intact:
cp optional/vite-bundle/package.json .
cp optional/vite-bundle/tsconfig.json .
cp optional/vite-bundle/vite.config.ts .
cp -r optional/vite-bundle/src .
cp -r optional/vite-bundle/test .

# Install deps (inside DDEV; battle scar §16 — node_modules + Mutagen).
ddev exec npm install

# Wire up the theme's libraries.yml — uncomment the dist/index.js
# block in web/themes/custom/<your-theme>/<your-theme>.libraries.yml.

# Build once.
ddev exec npm run build

# Or run the dev server (vite serves /src/index.ts directly).
ddev exec npm run dev

# Run tests.
ddev exec npm test
```

## What's in it

```
package.json       ← vite, vitest, typescript, @types/node
tsconfig.json      ← strict, ESNext modules, NodeNext resolution
vite.config.ts     ← outputs to web/themes/custom/example_theme/dist/
src/index.ts       ← entry stub (boots on DOMContentLoaded)
test/smoke.test.ts ← vitest smoke
```

## Notes

- Output path in `vite.config.ts` is `web/themes/custom/example_theme/dist/`
  — when you rename the theme, update that path.
- `outDir` is wiped on every build; keep nothing precious in `dist/`.
- `manualChunks` and code-splitting are not configured by default.
  Add them when the bundle crosses ~500 KB.
