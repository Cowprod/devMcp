#!/bin/sh
# Validate a disposable checkout. The live checkout is never written here.
set -eu
phase=${1:?Expected lint or tests}
cd "${2:?Expected a candidate checkout}"
case "$phase" in
    lint)
        find . -path './.git' -prune -o -path './vendor' -prune -o -type f -name '*.php' \
            -exec /bin/sh -ec 'for file do php -l "$file" >/dev/null; done' lint {} +
        find bin -type f ! -name '*.php' \
            -exec /bin/sh -ec 'for file do
                if head -n 2 "$file" | grep -Eq "^<\\?php|^#!.*[ /]php([[:space:]]|$)"; then
                    php -l "$file" >/dev/null
                fi
            done' lint {} +
        /bin/sh -n deploy/self-update.sh
        /bin/sh -n deploy/validate-update.sh
        echo 'Candidate syntax checks passed.'
        ;;
    tests)
        php vendor/bin/phpunit \
            tests/ServerFactoryTest.php tests/StdioDualEraTest.php tests/HttpTransportTest.php \
            tests/Mcp tests/Project/AdminUpdateMigrationTest.php tests/Project/WorkspaceAccessInstallerTest.php
        ;;
    *) exit 2 ;;
esac
