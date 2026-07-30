# LnkFlow Laravel

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `lnkflow/laravel`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Host Integration Database Scope

- Decide which SDK features the host application needs before publishing package migrations.
- API client, link management, journey capture, identity, and conversion reporting store their LnkFlow data remotely and require no package-owned database tables. Publish only `lnkflow-config` for these integrations.
- Publish and run `lnkflow-migrations` only when `features.content` is enabled. The two mapping tables belong exclusively to `ContentSynchronizer`.
- In the `0.1.0-beta.1` line, `lnkflow:install` publishes mapping migrations for every preset as a convenience behavior. For an integration without content synchronization, prefer config-only publishing; if the installer was already run, remove its uncommitted LnkFlow mapping migrations before migrating.
- Never add duplicate journey, conversion, token, or event tables to a host application. Journey and conversion records live in LnkFlow; the host should reuse its existing session and queue infrastructure.
- Package mapping tables never store API tokens. Tokens remain environment configuration and must not be committed.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
