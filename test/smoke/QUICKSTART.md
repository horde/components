# Smoke Test Quick Start

## One-Line Usage

```bash
cd ~/components && bash test/smoke/run-local-ci-smoke-test.sh
```

## What It Does

1. ✅ Checks prerequisites (PHP, Composer, horde-components)
2. ✅ Creates synthetic test component
3. ✅ Runs CI setup (creates 8 lanes)
4. ✅ Runs CI tests (PHPUnit, PHPStan)
5. ✅ Validates results
6. ✅ Reports pass/fail
7. ✅ Cleans up automatically

## Expected Time

- **First run:** 8-12 minutes (installs PHP versions)
- **Later runs:** 3-5 minutes (PHP already installed)

## Success Looks Like

```
╔═══════════════════════════════════════════════════════════╗
║          ✓ SMOKE TEST PASSED                              ║
╚═══════════════════════════════════════════════════════════╝

✓ All 8 lanes passed successfully
```

## Failure Looks Like

```
╔═══════════════════════════════════════════════════════════╗
║          ✗ SMOKE TEST FAILED                              ║
╚═══════════════════════════════════════════════════════════╝

✗ 2 of 8 lanes failed
```

## Troubleshooting

**"horde-components not found"**
→ Run from components directory: `cd ~/components`

**"PHP not found"**
→ Install PHP: `sudo apt-get install php8.4-cli`

**"Composer not found"**
→ Install Composer: https://getcomposer.org/download/

**Tests fail in PHP 8.5**
→ Expected (PHP 8.5 is in development)

## Full Documentation

See `test/smoke/README.md` for complete documentation.
