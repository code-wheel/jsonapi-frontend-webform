# Roadmap

## v1 (hybrid support)

- Extend `/jsonapi/resolve` so Webform routes resolve as non-headless:
  - Works for `/form/*` and aliased paths pointing to Webforms (e.g. `/contact` → `/form/contact`).
  - Enforces route access and returns “not found” for restricted forms.

## v1.1 (controls)

- Optional allow/deny list for Webforms (per-webform enable/disable).
- Optional UI integration on `/admin/config/services/jsonapi-frontend` via `hook_form_alter`.

## v2 (headless, optional / advanced)

- Evaluate a dedicated headless renderer flow (likely based on `webform_rest`).
- If we ship a renderer, consider a companion NPM package (framework-agnostic) instead of frontend-specific glue.
