# Skill: Bump Extension Version

**Trigger phrases:** "bump version", "release X.X.X", "update version to X", "new version"

---

## Files That Need Version Updates

The version string `1.1.7` appears in **4 places** — all must be updated together.

| File | Location | Format |
|------|----------|--------|
| `app/code/community/Razorpay/Payments/Model/Paymentmethod.php` | `const VERSION = '1.1.7'` | Semver string |
| `app/code/community/Razorpay/Payments/etc/config.xml` | `<version>1.1.7</version>` | Semver string |
| `package.xml` | Multiple fields | Semver string |
| `README.md` | Any version mentions | Semver string |

---

## Step-by-Step

### Step 1 — Determine New Version

Follow semver: `MAJOR.MINOR.PATCH`

| Change Type | Bump | Example |
|-------------|------|---------|
| Bug fix, minor tweak | PATCH | `1.1.7` → `1.1.8` |
| New feature, backward compatible | MINOR | `1.1.7` → `1.2.0` |
| Breaking change | MAJOR | `1.1.7` → `2.0.0` |

### Step 2 — Update `Paymentmethod.php`

File: `app/code/community/Razorpay/Payments/Model/Paymentmethod.php`

```php
// Change:
const VERSION = '1.1.7';
// To:
const VERSION = '1.2.0';  // use your new version
```

### Step 3 — Update `config.xml`

File: `app/code/community/Razorpay/Payments/etc/config.xml`

```xml
<!-- Change: -->
<Razorpay_Payments>
    <version>1.1.7</version>
</Razorpay_Payments>
<!-- To: -->
<Razorpay_Payments>
    <version>1.2.0</version>
</Razorpay_Payments>
```

### Step 4 — Update `package.xml`

File: `package.xml` (Magento Connect package descriptor)

Update the `<version>` tag and any date/notes:
```xml
<version>
    <release>1.2.0</release>
    <api>1.0.0</api>
</version>
<notes>...</notes>
<date>YYYY-MM-DD</date>
```

### Step 5 — Update README if Needed

Check `README.md` for any hardcoded version mentions and update them.

---

## Effect of `VERSION` Constant

The `VERSION` constant is used in the User-Agent string sent to Razorpay API:

```php
const CHANNEL_NAME = 'Razorpay/Magento%s_%s/%s';
// Produces: Razorpay/MagentoCE_1.9.4.1/1.1.7

public function _getChannel()
{
    return sprintf(self::CHANNEL_NAME, $edition, Mage::getVersion(), self::VERSION);
}
```

This helps Razorpay's support team identify the integration version when debugging issues.

---

## Verification

After bumping:
```bash
grep -r "1\.1\.7" --include="*.php" --include="*.xml" .
# Should return 0 results if all occurrences are updated

grep -r "1\.2\.0" --include="*.php" --include="*.xml" .
# Should show all 3 updated files
```
