# iHymns — a ready-made container for running the test suites
# ===========================================================
#
# WHAT THIS IS
# ------------
# A throwaway Linux box with PHP on it, so anyone can run this project's PHP
# test suites without installing PHP on their own machine. macOS has not
# shipped PHP since Monterey, so on a modern Mac `php tools/run-php-tests.php`
# simply reports "command not found" and 279 test suites go unrun.
#
# WHY IT IS COMMITTED
# -------------------
# Until now this container was something each developer had to work out and
# build by hand, from instructions that lived nowhere. Two people doing that
# independently get two subtly different PHP setups, and a test that passes
# for one fails for the other with nothing to point at. Committing the recipe
# makes it the same box for everyone, and turns "how do I run the PHP tests?"
# into two commands (see README.md next to this file).
#
# WHAT GOES IN IT AND WHY
# -----------------------
#  * php:8.3-cli — the plain command-line PHP image, matching the PHP version
#    the live site runs. https://hub.docker.com/_/php
#  * mysqli and pdo_mysql — the two database extensions this project's code
#    loads. Several test suites include real application files, and those
#    files refuse to load at all if the extension is missing, so without these
#    the suites fail for a reason that has nothing to do with what they test.
#    `docker-php-ext-install` is the helper the official PHP image provides
#    for compiling an extension in.
#  * nodejs and npm — a handful of test suites and helper scripts shell out to
#    node. Having it here means the same container can run either side.
#  * php-test.ini — raises the memory a single PHP process may use from the
#    stock 128 MB to 1 GB, because a couple of the test files read large SQL
#    files and run out otherwise. It goes in the image rather than on the
#    command line because the test runner starts each suite as its own fresh
#    process, and a command-line flag would not reach those. The full
#    reasoning is in that file.
#
# It deliberately does NOT contain a database server. Suites that want a live
# MySQL say "SKIP" and explain themselves when they cannot reach one; that is
# the intended, documented behaviour, not a gap this file should paper over.

FROM php:8.3-cli

# The database extensions the application code expects to be present.
RUN docker-php-ext-install mysqli pdo_mysql

# Node and npm, from Debian's own packages — no version pinning needed, since
# nothing here depends on a specific node release. `--no-install-recommends`
# and deleting the package lists afterwards just keep the image smaller.
RUN apt-get update \
    && apt-get install -y --no-install-recommends nodejs npm \
    && rm -rf /var/lib/apt/lists/*

# Settings that apply to every PHP process started in this container. The
# official image reads every .ini file in this folder at startup, which is what
# makes them reach the individual test processes the runner spawns, not just
# the runner itself. https://hub.docker.com/_/php
COPY php-test.ini "$PHP_INI_DIR/conf.d/zz-ihymns-test.ini"

# The repository gets mounted here by tools/test-env/php, and every path the
# test suites are handed is rewritten to sit underneath it.
WORKDIR /app
