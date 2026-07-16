#!/usr/bin/env bash
set -uo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAGENTO_ROOT="$(cd "$MODULE_DIR/../../../.." && pwd)"
VENDOR_BIN="$MAGENTO_ROOT/vendor/bin"
MODULE_REL="app/code/Razorpay/Magento"

# vendor/bin/* tools use "#!/usr/bin/env php", so they run whichever php is
# first on $PATH. In this MAMP-based environment that's Homebrew's PHP, which
# is NOT the PHP Magento itself is wired to (MAMP's bundled PHP has the
# correct mysqli.default_socket for MAMP's MySQL). Prefer the MAMP binary if
# it exists so vendor/bin/phpunit etc. actually connect to the same DB
# bin/magento does; fall back to plain "php" elsewhere.
MAMP_PHP="/Applications/MAMP/bin/php/php8.2.26/bin/php"
if [ -x "$MAMP_PHP" ]; then
    PHP_BIN="$MAMP_PHP"
else
    PHP_BIN="php"
fi

DIRS="Constants Controller Cron Model Observer Plugin Setup"

LOG_DIR="$MODULE_DIR/var/log/tests"
mkdir -p "$LOG_DIR"

# Tracks which targets ran and whether they passed, for the final summary.
RESULT_NAMES=()
RESULT_STATUSES=()
RESULT_LOGS=()

usage() {
    cat <<EOF
Usage: bin/run-tests.sh [target]

Targets:
  phpcs       Run PHP_CodeSniffer (Magento2 ruleset)
  phpcbf      Auto-fix PHP_CodeSniffer violations
  phpmd       Run PHP Mess Detector
  phpstan     Run PHPStan (level 5)
  static      Run phpcs + phpmd + phpstan
  unit        Run PHPUnit unit test suite
  compat      Run PHPCompatibility checks across the supported PHP version matrix
  integration Run PHPUnit integration test suite against dev/tests/integration
  all         Run static + compat + unit
  (none)      Same as "all"

Each target writes its own log file to:
  $LOG_DIR/<target>.log
EOF
}

# run_logged <name> <command...>
# Runs a command, tees combined stdout/stderr to var/log/tests/<name>.log,
# strips PHP's own deprecation noise from the console (kept in the log file),
# and records the pass/fail result for the end-of-run summary.
run_logged() {
    local name="$1"
    shift
    local log_file="$LOG_DIR/$name.log"

    echo "==> $name (log: $log_file)"

    set +e
    "$@" > "$log_file" 2>&1
    local status=$?

    grep -v -e '^PHP Deprecated' -e '^Deprecated:' "$log_file" || true

    RESULT_NAMES+=("$name")
    RESULT_LOGS+=("$log_file")
    if [ "$status" -eq 0 ]; then
        RESULT_STATUSES+=("PASS")
    else
        RESULT_STATUSES+=("FAIL")
    fi

    return "$status"
}

run_phpcs() {
    run_logged phpcs "$PHP_BIN" "$VENDOR_BIN/phpcs" --standard="$MAGENTO_ROOT/$MODULE_REL/phpcs.xml.dist"
}

run_phpcbf() {
    run_logged phpcbf "$PHP_BIN" "$VENDOR_BIN/phpcbf" --standard="$MAGENTO_ROOT/$MODULE_REL/phpcs.xml.dist"
}

run_phpmd() {
    run_logged phpmd bash -c "cd '$MODULE_DIR' && '$PHP_BIN' '$VENDOR_BIN/phpmd' '$(echo $DIRS | tr ' ' ',')' text phpmd.xml.dist"
}

run_phpstan() {
    run_logged phpstan "$PHP_BIN" "$VENDOR_BIN/phpstan" analyse -c "$MAGENTO_ROOT/$MODULE_REL/phpstan.neon" --memory-limit=1G
}

run_unit() {
    run_logged unit bash -c "cd '$MODULE_DIR' && '$PHP_BIN' '$VENDOR_BIN/phpunit' -c phpunit.xml"
}

run_compat() {
    run_logged compat "$MODULE_DIR/bin/check-php-compat.sh"
}

run_integration() {
    local integration_dir="$MAGENTO_ROOT/dev/tests/integration"
    if [ ! -f "$integration_dir/etc/install-config-mysql.php" ]; then
        echo "==> integration: SKIPPED"
        echo "    $integration_dir/etc/install-config-mysql.php not found."
        echo "    Copy etc/install-config-mysql.php.dist and configure a dedicated"
        echo "    test database first -- see TESTING.md 'Integration tests' section."
        RESULT_NAMES+=("integration")
        RESULT_LOGS+=("(skipped, see message above)")
        RESULT_STATUSES+=("SKIP")
        return 0
    fi

    run_logged integration bash -c "cd '$integration_dir' && '$PHP_BIN' '$VENDOR_BIN/phpunit' -c phpunit.xml.dist --filter Razorpay"
}

print_summary() {
    [ "${#RESULT_NAMES[@]}" -eq 0 ] && return
    echo ""
    echo "==> Summary"
    local overall=0
    for i in "${!RESULT_NAMES[@]}"; do
        printf '  %-10s %-6s %s\n' "${RESULT_NAMES[$i]}" "${RESULT_STATUSES[$i]}" "${RESULT_LOGS[$i]}"
        [ "${RESULT_STATUSES[$i]}" = "FAIL" ] && overall=1
    done
    return "$overall"
}

TARGET="${1:-all}"

case "$TARGET" in
    phpcs) run_phpcs ;;
    phpcbf) run_phpcbf ;;
    phpmd) run_phpmd ;;
    phpstan) run_phpstan ;;
    static) run_phpcs; run_phpmd; run_phpstan ;;
    unit) run_unit ;;
    compat) run_compat ;;
    integration) run_integration ;;
    all) run_phpcs; run_phpmd; run_phpstan; run_compat; run_unit ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown target: $TARGET"; usage; exit 1 ;;
esac

print_summary
exit $?
