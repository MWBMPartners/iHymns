#!/usr/bin/env bash
# classify-bump.sh — map conventional-commit records to a semver bump level.
# stdin:  git log --format='%s%x1f%b%x1e'  (0x1F between subject/body, 0x1E between records)
# stdout: exactly one word — "major", "minor" or "none".
# SAFE DEFAULT: anything unrecognised is "none" (build-only). Only an explicit
# conventional feat subject lifts to minor; only a subject `!` marker or a
# LINE-ANCHORED "BREAKING CHANGE:"/"BREAKING-CHANGE:" footer lifts to major.
set -euo pipefail
re_major='^[a-z]+(\([^)]*\))?!:'
re_minor='^feat(\([^)]*\))?:'
bump="none"
while IFS= read -r -d $'\x1e' rec || [[ -n "$rec" ]]; do
  subject="${rec%%$'\x1f'*}"
  body="${rec#*$'\x1f'}"; [[ "$body" == "$rec" ]] && body=""
  subject="${subject#$'\n'}"
  if [[ "$subject" =~ $re_major ]] || grep -qE '^BREAKING[ -]CHANGE:' <<<"$body"; then
    bump="major"; break
  fi
  [[ "$subject" =~ $re_minor ]] && bump="minor"
done
echo "$bump"
