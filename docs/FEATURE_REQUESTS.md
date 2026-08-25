# Feature requests

Proposed additions to the skeleton that have **not** been built yet.
Distinct from `docs/PROTOCOL.md` (decisions already taken) and
`docs/BATTLE_SCARS.md` (lessons already paid for): this file is the queue.

Each entry states the problem in terms of what a *future* project would hit,
the proposed shape, and what "done" looks like. Per the skeleton's own rule,
entries never name a specific project — a request that only makes sense as
biography is a request that belongs in that project's repo, not here.

When an entry ships, delete it from this file and record the result as a
`Dn` decision in `PROTOCOL.md` (plus a `CHANGELOG.md` line).

---

## FR-1 — `optional/commerce-bundle/`: Drupal Commerce as a drop-in

**Status:** proposed (2026-08-25)

### Problem

The skeleton is commerce-free, but a recurring class of build on this stack
is a storefront — and the platform's own definition of a "property" names
Drupal Commerce as part of the standard stack. The consequence is that a
commerce build minted from this skeleton starts *missing* something the
platform protocol assumes is present, and the omission is silent: the site
installs clean, tests pass, and nothing surfaces the gap until someone asks
where the store went. Every such build then re-derives the same sequence by
hand — require Commerce, pick the module set, import a currency, create a
store entity, discover that the store is content rather than config so it
never travels in `config/sync`.

### Proposal

A drop-in bundle mirroring `optional/vite-bundle/` — present in the repo,
copied in deliberately, **not** installed by default:

```
optional/commerce-bundle/
├── README.md                    what to copy where, and the caveats below
├── composer.commerce.json       the require fragment (core Commerce module set;
│                                recurring + license listed as opt-in extras)
└── scripts/setup_commerce.php   idempotent: import currency, create the store,
                                 register it as default
```

`setup_commerce.php` follows the `scripts/setup_*.php` convention already
established by `setup_mail.php`: idempotent, printing what it did, safe to
re-run in every environment. It must be **parameterised** (currency, country,
store name from arguments or environment) rather than hardcoding a market —
the skeleton does not get to assume where the next project sells.

### Caveats the bundle's README must carry

- **A store is a content entity, not config.** It does not travel in
  `config/sync`; every environment runs the setup script once. This is the
  single most surprising thing about deploying Commerce with a
  config-export-driven pipeline.
- **`minimum-stability`**: Commerce 3.x's dependency tree (notably
  `inline_entity_form`) needs a floor below `stable`. The skeleton already
  sets `dev` + `prefer-stable`, so this is satisfied — but say so, because
  it looks accidental otherwise.
- **Currency fraction digits.** The CLDR-derived digit count is not always
  the local convention (some currencies are quoted in whole units in
  practice), and a mismatch shows up as rounding noise on recurring charges.
  The script should make this an explicit knob.
- **Recurring billing needs a tokenising gateway.** `commerce_recurring` can
  only auto-charge renewals through a gateway implementing stored payment
  methods; redirect-only gateways force a manual renewal-invoice flow. A
  project that assumes otherwise discovers it after building the whole
  subscription model. Gateway choice stays a project decision — the bundle
  ships no gateway.

### Why optional rather than default

Skeleton rule: no vendor-locked choices. A brochure site should not carry
Commerce's dependency weight, its config surface, or its upgrade obligations
to satisfy the storefront case. Opt-in keeps the default mint small while
removing the re-derivation cost for the builds that want it.

### Done means

1. A fresh mint that copies the bundle and runs one setup command has a
   working store, in its own currency, with no hand-editing.
2. A fresh mint that ignores the bundle is byte-identical to today's and
   still passes the four health checks.
3. The bundle's README answers every caveat above without the reader
   needing to have hit it first.
