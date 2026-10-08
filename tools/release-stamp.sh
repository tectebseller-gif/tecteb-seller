#!/usr/bin/env bash
#
# The one timestamp every file of a release is stamped with.
#
# ### Why a timestamp has to be derived at all
#
# `build.sh` stamps every packaged file so two builds of the same source match
# byte for byte. A stamp taken from the clock, or from the last commit's date,
# makes the artefact's SHA-256 change on every commit — so the hash recorded in
# a delivery document can never survive the commit that records it, and a
# reviewer rebuilding from the delivered source cannot tell a timestamp
# difference from a content difference.
#
# ### Why it must DIFFER between releases
#
# PHP's opcache validates a cached file by `mtime` — not by size, not by hash.
# Every build from at least `alpha.35` to `alpha.37` stamped every file
# `2025-09-07 00:00`, so replacing the plugin's files left opcache serving the
# PREVIOUS build's compiled code, waiting on a timestamp that would never
# change. Measured on a real WordPress in `alpha.38`: with `alpha.35`'s files
# on disk, wp-admin answered 500 with a class-not-found error at a line number
# that only existed in `alpha.38`'s copy.
#
# ### Why it is not just the trailing number
#
# `alpha.38` derived the stamp from the release's trailing digits, which is
# collision-free inside ONE version line and nowhere else: `0.1.0-alpha.39`,
# `0.1.1-alpha.39` and `0.2.0-beta.39` all end in 39 and all got the same
# stamp. Two different packages with the same path and the same mtime is
# exactly the un-invalidatable upgrade this derivation exists to prevent, so
# the LINE is part of the answer now: the digits still move the stamp by a day
# each, and the prefix moves it by its own number of minutes within that day.
#
# `0.1.0-alpha.` keeps offset zero on purpose. Every package delivered so far
# belongs to that line, and a formula that changed their stamps would mean no
# delivered ZIP could be reproduced from its own source any more. That is a
# grandfather clause, written down rather than implied.
#
# Usage: bash tools/release-stamp.sh <version>   → prints the epoch seconds
# `SOURCE_DATE_EPOCH` is NOT read here; `build.sh` lets it override this.

set -euo pipefail

VERSION="${1:-}"
[ -n "${VERSION}" ] || { echo "usage: release-stamp.sh <version>" >&2; exit 2; }

# The base day, and the day-step per release number.
BASE=1757203200
DAY=86400

# The trailing run of digits. A version with none gets 0, which is a real
# answer (an unnumbered build) rather than an error.
SEQ="$(printf '%s' "${VERSION}" | sed -n 's/.*[^0-9]\([0-9]\{1,\}\)$/\1/p')"
SEQ="${SEQ:-0}"

# Everything before those digits: the version LINE.
PREFIX="${VERSION%"${SEQ}"}"

if [ "${PREFIX}" = "0.1.0-alpha." ]; then
    LINE_MINUTES=0
else
    # Deterministic, stable across machines, and inside one day so the
    # per-release day-step keeps its meaning. 1439 minutes, not 1440, so no
    # line can land exactly on the next day's zero offset.
    HASH="$(printf '%s' "${PREFIX}" | sha256sum | cut -c1-8)"
    LINE_MINUTES=$(( (16#${HASH}) % 1439 + 1 ))
fi

printf '%s\n' "$(( BASE + SEQ * DAY + LINE_MINUTES * 60 ))"
