# Running the PHP tests without installing PHP

macOS no longer ships PHP, so `php tools/run-php-tests.php` usually just says
"command not found". This folder holds a small Linux container with PHP in it,
plus a stand-in `php` command that quietly runs everything inside that
container — so the normal instructions work unchanged.

**Build the container** (once, from the top of the repository):

    docker build -t ihymns-php:8.3 tools/test-env

**Put the stand-in on your PATH** (add this line to `~/.zshrc`):

    export PATH="/path/to/iHymns/tools/test-env:$PATH"

**Run the tests** — exactly as you would with a real PHP:

    php tools/run-php-tests.php     # the PHP suites
    npm test                        # the JavaScript suites

Both finish with a single `TEST RESULT: PASS (...)` or `TEST RESULT: FAIL (...)`
line, so there is nothing to interpret.

One thing worth knowing: inside the container the repository is mounted at
`/app`, not at the path it has on your machine, so the stand-in rewrites that
part of any path it is handed. Without that, the test suites — which pass full
paths to one another — would be pointing at files that do not exist in there.
