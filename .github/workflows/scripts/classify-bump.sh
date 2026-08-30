#!/usr/bin/env bash
# classify-bump.sh — map conventional-commit records to a semver bump level.
# stdin:  git log --format='%s%x1f%b%x1e'  (0x1F between subject/body, 0x1E between records)
# stdout: exactly one word — "major", "minor", "patch" or "none".
# SAFE DEFAULT: anything unrecognised is "none" (build-only). Only an explicit
# conventional feat subject lifts to minor; only a subject `!` marker or a
# LINE-ANCHORED "BREAKING CHANGE:"/"BREAKING-CHANGE:" footer lifts to major;
# only a WHOLE-LINE `Release: patch` body footer (case-insensitive — a house
# marker, unlike the spec-exact BREAKING CHANGE token) lifts to patch. A plain
# fix:/chore:/docs: with no footer stays "none" — the deliberate design: the
# web deploys continuously and must not churn its version on routine fixes,
# while a patch release is an explicit, human choice (mint on clear signals
# only). Precedence across the range: major > minor > patch > none.
# @see https://www.conventionalcommits.org/en/v1.0.0/
# @see .claude/CLAUDE.md rule #46 (the full versioning contract)
set -euo pipefail
re_major='^[a-z]+(\([^)]*\))?!:'
re_minor='^feat(\([^)]*\))?:'
# Whole-line, case-insensitive "Release: patch" — mirrors the BREAKING CHANGE
# footer's line-anchored ('^') discipline, but ALSO anchors the END of the
# line ('$', modulo optional trailing spaces) because our marker takes no
# value after the colon (unlike BREAKING CHANGE, whose spec form carries a
# description) — so prose like "Release: patch notes are in the wiki" can
# never fire it by accident.
re_patch='^release:[[:space:]]*patch[[:space:]]*$'
bump="none"
while IFS= read -r -d $'\x1e' rec || [[ -n "$rec" ]]; do
  subject="${rec%%$'\x1f'*}"
  body="${rec#*$'\x1f'}"; [[ "$body" == "$rec" ]] && body=""
  subject="${subject#$'\n'}"
  if [[ "$subject" =~ $re_major ]] || grep -qE '^BREAKING[ -]CHANGE:' <<<"$body"; then
    bump="major"; break
  fi
  if [[ "$subject" =~ $re_minor ]]; then
    bump="minor"
  elif [[ "$bump" == "none" ]] && grep -qiE "$re_patch" <<<"$body"; then
    # The `bump == "none"` guard is load-bearing two ways: (a) a later
    # patch-footer record can never DOWNGRADE an already-found "minor" set by
    # an earlier feat; (b) a record that is itself feat AND carries the
    # footer takes the `if` branch above -> minor, never reaching here.
    bump="patch"
  fi
done
echo "$bump"
