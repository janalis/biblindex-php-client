#!/usr/bin/env bash
# Bump the package version by tagging the next semver and pushing the tag.
# The Release workflow then runs the test suite and, when green, creates the
# GitHub Release and notifies Packagist.
set -euo pipefail

PART="${1:-patch}"

if [[ ! "$PART" =~ ^(patch|minor|major)$ ]]; then
  echo "Usage: $0 [patch|minor|major]" >&2
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Working tree is not clean; commit or stash first." >&2
  exit 1
fi

git fetch --tags --quiet
current="$(git tag --list 'v*' --sort=-v:refname | head -n1)"
current="${current:-v0.0.0}"
IFS=. read -r major minor patch <<<"${current#v}"

case "$PART" in
  major) major=$((major + 1)) minor=0 patch=0 ;;
  minor) minor=$((minor + 1)) patch=0 ;;
  patch) patch=$((patch + 1)) ;;
esac

next="v${major}.${minor}.${patch}"
echo "Bumping ${current} -> ${next}"
git tag --annotate "$next" --message "Release $next"
git push origin "$next"
echo "Pushed ${next} — the Release workflow now runs tests and publishes."
