# Raw-Wire Dashboard Tests

## Overview

This directory contains unit tests and integration tests for the Raw-Wire Dashboard plugin.

## Test Structure

```
tests/
├── bootstrap.php          # Test bootstrap and WordPress test setup
├── test-logger.php        # Logger class tests
└── README.md             # This file
```

## Running Tests

### Prerequisites

1. **PHPUnit**: Install PHPUnit (version 8.5+ for PHP 7.4, 9.0+ for PHP 8.0+)
   ```bash
   composer global require phpunit/phpunit
   ```

2. **WordPress Test Suite** (Optional but recommended):
   ```bash
   # Install WordPress test suite
   bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
   ```

### Basic Test Run

From the repository root:

```bash
# Run all tests
phpunit

# Run specific test file
phpunit wordpress-plugins/raw-wire-dashboard/tests/test-logger.php

# Run with verbose output
phpunit --verbose
```

### Without WordPress Test Suite

The tests can run in a minimal mode without the WordPress test suite. Some WordPress functions are mocked in `bootstrap.php`:

```bash
phpunit
```

**Note**: Full integration testing requires WordPress test suite for database operations and WordPress core functions.

### With WordPress Test Suite

Set environment variables to enable full WordPress integration:

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WP_CORE_DIR=/tmp/wordpress/
phpunit
```

## Test Coverage

### Phase 1 (Current)

- ✅ Logger class structure tests
- ✅ Method existence validation
- ✅ Parameter validation

### Phase 2+ (TODO)

- [ ] GitHub Fetcher tests
  - [ ] Token validation
  - [ ] API request handling
  - [ ] Error handling
  - [ ] Rate limit checking
  
- [ ] Data Processor tests
  - [ ] Data sanitization
  - [ ] Relevance scoring
  - [ ] Field validation
  - [ ] Metadata handling
  
- [ ] Approval Workflow tests
  - [ ] Status changes
  - [ ] Permission checks
  - [ ] Bulk operations
  - [ ] History tracking
  
- [ ] Cache Manager tests
  - [ ] Cache operations
  - [ ] TTL expiration
  - [ ] Invalidation
  - [ ] Group management
  
- [ ] Dashboard Core tests
  - [ ] Singleton pattern
  - [ ] Activation/deactivation
  - [ ] Hook registration

## Writing Tests

### Test Class Template

```php
<?php
/**
 * Test class description
 *
 * @package    RawWire_Dashboard
 * @subpackage RawWire_Dashboard/tests
 */

class Test_RawWire_ClassName extends PHPUnit\Framework\TestCase {

    protected function setUp(): void {
        parent::setUp();
        // Set up test fixtures
    }

    protected function tearDown(): void {
        // Clean up after tests
        parent::tearDown();
    }

    public function test_method_exists() {
        $this->assertTrue(
            method_exists( 'RawWire_ClassName', 'method_name' )
        );
    }

    public function test_expected_behavior() {
        // Arrange
        $input = 'test value';
        
        // Act
        $result = RawWire_ClassName::method_name( $input );
        
        // Assert
        $this->assertEquals( 'expected', $result );
    }
}
```

### WordPress Mock Functions

When WordPress test suite is not available, these functions are mocked in `bootstrap.php`:

- `sanitize_text_field()`
- `wp_json_encode()`
- `current_time()`
- `esc_url_raw()`
- `wp_kses_post()`
- `WP_Error` class
- `is_wp_error()`

For full WordPress functionality, install the WordPress test suite.

## Continuous Integration

Tests run automatically in GitHub Actions CI on:
- Push to `main` branch
- Pull requests to `main`
- Push to `feature/*` branches

See `.github/workflows/ci.yml` for CI configuration.

## Test Database

For integration tests with database operations:

1. Create a test database:
   ```sql
   CREATE DATABASE wordpress_test;
   GRANT ALL ON wordpress_test.* TO 'wp_test_user'@'localhost';
   ```

2. Configure in CI (GitHub Secrets):
   - `WP_DB_HOST`
   - `WP_DB_NAME`
   - `WP_DB_USER`
   - `WP_DB_PASSWORD`

## Best Practices

- ✓ One test class per plugin class
- ✓ Descriptive test method names (`test_method_does_what`)
- ✓ Arrange-Act-Assert pattern
- ✓ Mock external dependencies
- ✓ Clean up test data in `tearDown()`
- ✓ Test both success and failure cases
- ✓ Test edge cases and boundaries
- ✓ Keep tests isolated and independent

## Debugging Tests

### Enable Verbose Output

```bash
phpunit --verbose --debug
```

### Run Single Test Method

```bash
phpunit --filter test_method_name
```

### Show Test Coverage

```bash
phpunit --coverage-html coverage/
```

Then open `coverage/index.html` in a browser.

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Plugin Testing](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)

## Contributing

When adding new functionality:

1. Write tests first (TDD approach recommended)
2. Ensure all tests pass before committing
3. Aim for high test coverage (>80%)
4. Follow WordPress coding standards
5. Document complex test scenarios

---

**Last Updated**: Phase 1 Implementation
