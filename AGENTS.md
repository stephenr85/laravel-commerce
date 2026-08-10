> You are in **rushing/laravel-commerce** — the shared money-in engine for the ecosystem: a neutral Order -> Payment -> Receipt -> Purchase primitive behind a fake|stripe driver seam, plus billing composition, spend gating, credit/wallet, gifting, and a catalog.

Consumed directly by splicewire-app and every satellite. Never merchant-of-record.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
