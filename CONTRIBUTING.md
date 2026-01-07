# Contributing to Horizon Running Jobs

Thank you for considering contributing to Horizon Running Jobs! 🎉

## How to Contribute

### Reporting Bugs

If you find a bug, please open an issue on GitHub with:

1. A clear title and description
2. Steps to reproduce the issue
3. Expected vs actual behavior
4. Your environment (PHP version, Laravel version, Horizon version)

### Suggesting Features

Feature requests are welcome! Please open an issue describing:

1. The problem you're trying to solve
2. Your proposed solution
3. Any alternatives you've considered

### Pull Requests

1. **Fork the repository** and create your branch from `main`

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Make your changes** and ensure:
   - Code follows PSR-12 coding standards
   - All existing tests pass
   - New features include tests
   - Documentation is updated if needed

4. **Run tests:**
   ```bash
   composer test
   ```

5. **Submit your pull request** with:
   - A clear title and description
   - Reference to any related issues

### Coding Standards

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding style
- Use meaningful variable and method names
- Add docblocks for public methods
- Keep methods focused and small

### Commit Messages

Use clear, descriptive commit messages:

```
feat: add support for custom Redis connections
fix: resolve caching issue with multiple queues
docs: update installation instructions
test: add tests for statistics endpoint
```

### Testing

Please ensure all tests pass before submitting a PR:

```bash
# Run all tests
composer test

# Run specific test
./vendor/bin/phpunit --filter TestName
```

## Development Setup

1. Clone your fork:
   ```bash
   git clone https://github.com/ashiqfardus/laravel-horizon-running-jobs.git
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Create a branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```

4. Make changes and test locally

5. Push and create a pull request

## Questions?

Feel free to open an issue or reach out to [ashiqfardus@hotmail.com](mailto:ashiqfardus@hotmail.com).

Thank you for contributing! 🚀

