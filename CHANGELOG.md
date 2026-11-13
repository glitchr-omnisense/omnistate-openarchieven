# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnistate Open Archives: births, baptisms, marriages, deaths, burials and more from the indexes of Dutch and Belgian archives (openarchieven.nl), with the archive's reference and the scan of the act.

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnistate: ^1.0`: stable versions, so `composer require omnistate/openarchieven` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
