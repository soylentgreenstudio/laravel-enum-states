# Contributing to Laravel Enum States

Thank you for considering contributing to Laravel Enum States!

## Getting Started

1. Fork the repository
2. Clone your fork: `git clone git@github.com:YOUR_USERNAME/laravel-enum-states.git`
3. Install dependencies: `composer install`
4. Create a branch: `git checkout -b my-feature`

## Development

### Running Tests

```bash
composer test
# or
./vendor/bin/pest
```

Tests use Orchestra Testbench with an in-memory SQLite database — no external services needed.

### Code Style

- Follow PSR-12 coding standards
- Use PHP 8.1+ features (enums, attributes, named arguments, etc.)
- Add tests for any new functionality

## Pull Requests

1. Update tests to cover your changes
2. Ensure the full test suite passes: `composer test`
3. Write a clear PR description explaining **what** and **why**
4. Keep PRs focused — one feature or fix per PR

## Bug Reports

When filing a bug report, please include:

- PHP and Laravel versions
- Package version
- Minimal code to reproduce the issue
- Expected vs actual behavior

## Feature Requests

Open an issue describing the feature, its use case, and how it fits with the package's philosophy of using native PHP enums and attributes.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.