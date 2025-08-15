# Contributing Guide

Thank you for considering contributing to the Laravel Dynamic Properties package! This guide will help you understand how to contribute effectively.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Contributing Process](#contributing-process)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Documentation](#documentation)
- [Submitting Changes](#submitting-changes)

## Code of Conduct

This project adheres to a code of conduct that we expect all contributors to follow. Please read and follow our [Code of Conduct](CODE_OF_CONDUCT.md) to help us maintain a welcoming and inclusive community.

## Getting Started

### Types of Contributions

We welcome several types of contributions:

- **Bug Reports**: Help us identify and fix issues
- **Feature Requests**: Suggest new functionality
- **Code Contributions**: Submit bug fixes or new features
- **Documentation**: Improve or add documentation
- **Testing**: Add or improve test coverage
- **Performance**: Optimize existing functionality

### Before You Start

1. **Check existing issues**: Look through existing issues and pull requests to avoid duplicates
2. **Discuss major changes**: For significant changes, please open an issue first to discuss the approach
3. **Read the documentation**: Familiarize yourself with the package architecture and design principles

## Development Setup

### Prerequisites

- PHP 8.1 or higher
- Composer
- Git
- A supported database (MySQL, PostgreSQL, or SQLite)

### Local Development Environment

1. **Fork and clone the repository**:
```bash
git clone https://github.com/your-username/laravel-dynamic-properties.git
cd laravel-dynamic-properties
```

2. **Install dependencies**:
```bash
composer install
```

3. **Set up environment**:
```bash
cp .env.example .env
# Edit .env with your database configuration
```

4. **Run migrations**:
```bash
php artisan migrate
```

5. **Run tests to verify setup**:
```bash
./vendor/bin/pest
```

### Development Tools

We recommend using the following tools:

- **IDE**: PhpStorm, VS Code with PHP extensions
- **Code Quality**: PHP CS Fixer, PHPStan
- **Testing**: Pest (included)
- **Database**: TablePlus, Sequel Pro, or similar

## Contributing Process

### 1. Create an Issue (for bugs/features)

Before starting work, create an issue describing:

- **Bug reports**: Steps to reproduce, expected vs actual behavior, environment details
- **Feature requests**: Use case, proposed solution, potential impact

### 2. Fork and Branch

```bash
# Fork the repository on GitHub, then:
git clone https://github.com/your-username/laravel-dynamic-properties.git
cd laravel-dynamic-properties

# Create a feature branch
git checkout -b feature/your-feature-name
# or
git checkout -b fix/issue-description
```

### 3. Make Changes

Follow our [coding standards](#coding-standards) and ensure:

- Code is well-documented
- Tests are included
- Existing tests pass
- Code follows PSR-12 standards

### 4. Test Your Changes

```bash
# Run all tests
./vendor/bin/pest

# Run specific test suites
./vendor/bin/pest tests/Unit
./vendor/bin/pest tests/Feature

# Run with coverage
./vendor/bin/pest --coverage
```

### 5. Submit Pull Request

Create a pull request with:

- Clear title and description
- Reference to related issues
- List of changes made
- Screenshots (if applicable)

## Coding Standards

### PHP Standards

We follow PSR-12 coding standards with some additional conventions:

#### Code Style

```php
<?php

namespace YourVendor\DynamicProperties\Services;

use Illuminate\Database\Eloquent\Model;
use YourVendor\DynamicProperties\Models\Property;

class PropertyService
{
    /**
     * Set a property value for an entity.
     *
     * @param Model $entity The entity to set the property on
     * @param string $name The property name
     * @param mixed $value The property value
     * @return void
     * @throws PropertyNotFoundException
     * @throws PropertyValidationException
     */
    public function setDynamicProperty(Model $entity, string $name, mixed $value): void
    {
        // Implementation...
    }
}
```

#### Naming Conventions

- **Classes**: PascalCase (`PropertyService`)
- **Methods**: camelCase (`setDynamicProperty`)
- **Variables**: camelCase (`$propertyName`)
- **Constants**: SCREAMING_SNAKE_CASE (`MAX_PROPERTY_LENGTH`)
- **Database tables**: snake_case (`entity_properties`)
- **Database columns**: snake_case (`property_name`)

#### Documentation

All public methods must have PHPDoc comments:

```php
/**
 * Brief description of what the method does.
 *
 * Longer description if needed, explaining the purpose,
 * behavior, or important details.
 *
 * @param Type $param Description of parameter
 * @return Type Description of return value
 * @throws ExceptionType When this exception is thrown
 */
public function methodName(Type $param): Type
{
    // Implementation
}
```

### Database Standards

#### Migration Conventions

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_name', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            
            // Indexes
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_name');
    }
};
```

#### Model Conventions

```php
<?php

namespace YourVendor\DynamicProperties\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityProperty extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'entity_id',
        'entity_type',
        'property_id',
        'string_value',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'date_value' => 'date',
        'boolean_value' => 'boolean',
    ];

    /**
     * Get the property definition.
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
```

## Testing

### Test Structure

We use Pest for testing with the following structure:

```
tests/
├── Unit/           # Unit tests for individual classes
├── Feature/        # Feature tests for complete workflows
├── Pest.php        # Pest configuration
└── TestCase.php    # Base test case
```

### Writing Tests

#### Unit Tests

```php
<?php

use YourVendor\DynamicProperties\Models\Property;
use YourVendor\DynamicProperties\Services\PropertyService;

describe('PropertyService', function () {
    beforeEach(function () {
        $this->service = new PropertyService();
        $this->property = Property::factory()->create([
            'name' => 'test_property',
            'type' => 'text',
        ]);
    });

    it('can set a property value', function () {
        $user = User::factory()->create();
        
        $this->service->setDynamicProperty($user, 'test_property', 'test value');
        
        expect($user->getDynamicProperty('test_property'))->toBe('test value');
    });

    it('throws exception for invalid property', function () {
        $user = User::factory()->create();
        
        expect(fn() => $this->service->setDynamicProperty($user, 'invalid', 'value'))
            ->toThrow(PropertyNotFoundException::class);
    });
});
```

#### Feature Tests

```php
<?php

use App\Models\User;
use YourVendor\DynamicProperties\Models\Property;

describe('Property Search', function () {
    beforeEach(function () {
        Property::factory()->create([
            'name' => 'department',
            'type' => 'text',
        ]);
    });

    it('can search users by property', function () {
        $engineeringUser = User::factory()->create();
        $marketingUser = User::factory()->create();
        
        $engineeringUser->setDynamicProperty('department', 'engineering');
        $marketingUser->setDynamicProperty('department', 'marketing');
        
        $results = User::whereProperty('department', 'engineering')->get();
        
        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($engineeringUser->id);
    });
});
```

### Test Coverage

Maintain high test coverage:

- **Unit tests**: Test individual methods and classes
- **Feature tests**: Test complete user workflows
- **Integration tests**: Test database interactions
- **Performance tests**: Test performance characteristics

### Running Tests

```bash
# Run all tests
./vendor/bin/pest

# Run specific test file
./vendor/bin/pest tests/Unit/PropertyServiceTest.php

# Run with coverage
./vendor/bin/pest --coverage

# Run with coverage and generate HTML report
./vendor/bin/pest --coverage --coverage-html coverage
```

## Documentation

### Types of Documentation

1. **Code Documentation**: PHPDoc comments in code
2. **API Documentation**: Method and class references
3. **User Documentation**: Usage guides and examples
4. **Developer Documentation**: Architecture and contribution guides

### Writing Documentation

#### Code Comments

```php
/**
 * Set multiple properties for an entity efficiently.
 *
 * This method optimizes bulk property setting by batching
 * database operations and synchronizing the JSON cache once
 * at the end.
 *
 * @param Model $entity The entity to set properties on
 * @param array $properties Associative array of property names and values
 * @return void
 * @throws PropertyNotFoundException If any property doesn't exist
 * @throws PropertyValidationException If any value fails validation
 */
public function setProperties(Model $entity, array $properties): void
{
    // Implementation...
}
```

#### README Updates

When adding features, update relevant documentation:

- Main README.md
- API documentation
- Usage examples
- Performance characteristics

#### Documentation Style

- Use clear, concise language
- Include code examples
- Explain the "why" not just the "how"
- Keep examples up to date

## Submitting Changes

### Pull Request Guidelines

#### Before Submitting

- [ ] Tests pass locally
- [ ] Code follows style guidelines
- [ ] Documentation is updated
- [ ] Commit messages are clear
- [ ] Branch is up to date with main

#### Pull Request Template

```markdown
## Description
Brief description of changes made.

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Related Issues
Fixes #123

## Changes Made
- List of specific changes
- Another change
- etc.

## Testing
- [ ] Unit tests added/updated
- [ ] Feature tests added/updated
- [ ] All tests pass
- [ ] Manual testing completed

## Documentation
- [ ] Code comments updated
- [ ] API documentation updated
- [ ] README updated (if needed)
- [ ] Examples updated (if needed)

## Screenshots (if applicable)
Add screenshots here.

## Checklist
- [ ] My code follows the project's style guidelines
- [ ] I have performed a self-review of my code
- [ ] I have commented my code, particularly in hard-to-understand areas
- [ ] I have made corresponding changes to the documentation
- [ ] My changes generate no new warnings
- [ ] I have added tests that prove my fix is effective or that my feature works
- [ ] New and existing unit tests pass locally with my changes
```

### Commit Message Format

Use conventional commit format:

```
type(scope): description

[optional body]

[optional footer]
```

**Types:**
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, etc.)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

**Examples:**
```
feat(search): add support for BETWEEN operator in property search

fix(validation): handle null values in number property validation

docs(api): update PropertyService documentation with new methods

test(unit): add tests for property validation edge cases
```

### Review Process

1. **Automated Checks**: CI/CD runs tests and code quality checks
2. **Code Review**: Maintainers review code for quality and design
3. **Testing**: Changes are tested in various environments
4. **Documentation Review**: Documentation changes are reviewed
5. **Approval**: Changes are approved and merged

### After Submission

- Respond to feedback promptly
- Make requested changes
- Keep the PR updated with main branch
- Be patient during the review process

## Getting Help

### Communication Channels

- **GitHub Issues**: For bugs and feature requests
- **GitHub Discussions**: For questions and general discussion
- **Email**: For security issues or private matters

### Resources

- **Laravel Documentation**: https://laravel.com/docs
- **PHP Documentation**: https://php.net/docs
- **Pest Documentation**: https://pestphp.com/docs
- **PSR Standards**: https://www.php-fig.org/psr/

### Mentorship

New contributors are welcome! If you're new to open source or need help getting started:

- Look for issues labeled "good first issue"
- Ask questions in GitHub Discussions
- Reach out to maintainers for guidance

## Recognition

Contributors are recognized in several ways:

- Listed in CHANGELOG.md for their contributions
- Added to the contributors list
- Mentioned in release notes for significant contributions

Thank you for contributing to Laravel Dynamic Properties! Your efforts help make this package better for everyone.