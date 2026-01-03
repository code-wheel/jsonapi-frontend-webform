# JSON:API Frontend Webform

[![Drupal Module](https://github.com/code-wheel/jsonapi-frontend-webform/actions/workflows/drupal-module.yml/badge.svg?branch=master)](https://github.com/code-wheel/jsonapi-frontend-webform/actions/workflows/drupal-module.yml?query=branch%3Amaster) [![Semgrep](https://github.com/code-wheel/jsonapi-frontend-webform/actions/workflows/semgrep.yml/badge.svg?branch=master)](https://github.com/code-wheel/jsonapi-frontend-webform/actions/workflows/semgrep.yml?query=branch%3Amaster) [![codecov](https://codecov.io/gh/code-wheel/jsonapi-frontend-webform/branch/master/graph/badge.svg)](https://codecov.io/gh/code-wheel/jsonapi-frontend-webform) [![Security Policy](https://img.shields.io/badge/security-policy-blue.svg)](SECURITY.md)

Optional integration module for [`jsonapi_frontend`](https://www.drupal.org/project/jsonapi_frontend) that makes Drupal Webforms work cleanly in hybrid headless setups.

Project page: https://www.drupal.org/project/jsonapi_frontend_webform

## What it does

Drupal Webform routes are not content entities, so they don’t naturally map to a JSON:API resource URL. This module extends `/jsonapi/resolve` so that Webform routes (including aliased paths like `/contact`) resolve as **non-headless** and return a `drupal_url`.

This enables:

- **Split routing:** frontends can redirect to Drupal for Webform pages.
- **Frontend-first:** frontends can proxy Webform routes to the Drupal origin.

## Install

```bash
composer require drupal/webform
composer require drupal/jsonapi_frontend_webform
drush en jsonapi_frontend_webform
```

## Notes

- This module is intentionally **hybrid-first**: it keeps Webform rendering + submissions on Drupal.
- Fully headless Webform rendering is a separate problem (REST resources, auth, CORS/CSRF, file uploads). See `ROADMAP.md`.
