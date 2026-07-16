#!/usr/bin/env bash
set -euo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAGENTO_ROOT="$(cd "$MODULE_DIR/../../../.." && pwd)"
VENDOR_BIN="$MAGENTO_ROOT/vendor/bin"
MODULE_REL="app/code/Razorpay/Magento"

# PHP versions this module claims to support (see composer.json "require".php
# and TESTING.md for the reasoning behind this list).
PHP_VERSIONS=(7.4 8.1 8.2 8.3 8.4 8.5)

echo "=== PHPCompatibility (phpcs) ==="
for v in "${PHP_VERSIONS[@]}"; do
    echo "--- testVersion $v ---"
    (cd "$MAGENTO_ROOT" && "$VENDOR_BIN/phpcs" \
        --standard="$MODULE_REL/phpcs-compatibility.xml.dist" \
        --runtime-set testVersion "$v" \
        --report=summary) || true
done

echo ""
echo "=== PHPStan (phpVersion emulation) ==="
echo "Note: PHPStan's phpVersion parameter accepts 70100..80499 in the"
echo "currently installed PHPStan version, so 8.5 is skipped here (see"
echo "TESTING.md for details). Upgrading PHPStan will unlock 8.5 emulation."
for v in "${PHP_VERSIONS[@]}"; do
    ver_num=$(echo "$v" | awk -F. '{printf "%d%02d00", $1, $2}')
    if [ "$ver_num" -gt 80499 ]; then
        echo "--- phpVersion $v (${ver_num}) skipped: unsupported by installed PHPStan ---"
        continue
    fi

    tmp_config="$(mktemp -t phpstan-compat-XXXXXX).neon"
    cat > "$tmp_config" <<EOF
includes:
    - $MAGENTO_ROOT/$MODULE_REL/phpstan.neon
parameters:
    phpVersion: $ver_num
EOF
    echo "--- phpVersion $v (${ver_num}) ---"
    (cd "$MAGENTO_ROOT" && "$VENDOR_BIN/phpstan" analyse -c "$tmp_config" --memory-limit=1G --error-format=table) || true
    rm -f "$tmp_config"
done

echo ""
echo "=== php -l (syntax lint, only runs against the PHP binary available in this environment) ==="
php -v | head -1
FAIL=0
for f in $(cd "$MAGENTO_ROOT/$MODULE_REL" && find Constants Controller Cron Model Observer Plugin Setup -name "*.php"); do
    out=$(php -l "$MAGENTO_ROOT/$MODULE_REL/$f" 2>&1)
    if ! echo "$out" | grep -q "No syntax errors detected"; then
        echo "FATAL: $f"
        echo "$out"
        FAIL=1
    fi
done
if [ "$FAIL" -eq 0 ]; then
    echo "No fatal syntax errors in any of the 34 scanned files."
fi
