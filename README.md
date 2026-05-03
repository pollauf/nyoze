# Nyoze

https://nyoze.pollauf.net

Entity-centric PHP framework. Declare entities, fields, actions, and rules, Nyoze handles routing, validation, persistence, and the HTTP lifecycle.

## Philosophy

- **Entity-first.** The entity is the center of the system. Everything else serves it.
- **Capability-driven.** Entities don't "have CRUD". They expose capabilities. Everything is an action.
- **Low boilerplate.** Declare intent, not infrastructure.
- **Separation of concerns.** Entities declare structure. Actions contain logic. Repositories handle data.
- **No magic.** Fluent API, explicit wiring, predictable behavior.
- **Portable.** Zero dependencies beyond PHP 8.2. No coupling to Laravel, Symfony, or any other framework.

## Installation

```bash
composer require pollauf/nyoze
```

Requires PHP 8.2+.

## Quick Start

### 1. Entry Point

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use App\Context;
use Nyoze\Core\Kernel;
use Nyoze\Data\PdoRepository;
use Nyoze\Support\Config;

$config = Config::fromEnv(__DIR__ . '/.env');

$kernel = Kernel::load(function (\Nyoze\Core\App $app) use ($config) {
    $dsn = $config->get('DB_DSN', 'sqlite:database.sqlite');
    $pdo = new PDO($dsn, $config->get('DB_USER'), $config->get('DB_PASS'));
    $app->useRepository(new PdoRepository($pdo));
    (new Context())->register($app);
});

$kernel->app()->run();
```

### 2. Context

The `Context` class registers all entity definitions with the application:

```php
namespace App;

use App\Entities\TasksEntity;
use Nyoze\Core\App;

class Context
{
    public function register(App $app): void
    {
        $app->load([
            TasksEntity::class,
        ]);
    }
}
```

### 3. Entity

```php
namespace App\Entities;

use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\ListTasksAction;
use Nyoze\Domain\Entity;
use Nyoze\Domain\EntityDefinition;
use Nyoze\Domain\Field;

class TasksEntity extends EntityDefinition
{
    public function name(): string { return 'tasks'; }

    public function define(Entity $entity): void
    {
        $entity
            ->fields(
                Field::string('title')->required(),
                Field::text('description'),
                Field::string('status')->default('pending'),
                Field::datetime('created_at')->defaultNow(),
            )
            ->can('create', CreateTaskAction::class)->post()
            ->can('list', ListTasksAction::class)->get();
    }
}
```

### 4. Action

```php
namespace App\Actions\Tasks;

use Nyoze\Domain\ActionContext;
use Nyoze\Domain\Result;

class CreateTaskAction
{
    public function __invoke(ActionContext $ctx): Result
    {
        $task = $ctx->repo()->save('tasks', [
            'title'       => $ctx->input('title'),
            'description' => $ctx->input('description', ''),
            'status'      => $ctx->input('status', 'pending'),
            'created_at'  => $ctx->now(),
        ]);

        return Result::created($task);
    }
}
```

This generates:

| Method | Endpoint | Action |
|--------|----------|--------|
| `POST` | `/tasks/create` | `CreateTaskAction` |
| `GET` | `/tasks/list` | `ListTasksAction` |

## Project Structure

```
my-project/
├── App/
│   ├── Entities/       # EntityDefinition classes
│   ├── Actions/        # Business logic (grouped by domain)
│   ├── Repositories/   # Custom repository classes
│   ├── Resources/      # Output transformers
│   ├── Rules/          # Domain invariants
│   └── Support/        # Helpers and utilities
├── vendor/
├── composer.json
├── index.php
└── .env
```

## Concepts

### Entity

Every domain concept is modeled as an entity extending `EntityDefinition`. An entity declares its fields, actions, invariants, relations, and hooks in a single class.

```php
$entity
    ->auth()                        // require authentication
    ->ownedBy('id_user')            // scope queries by user
    ->fields(...)                   // declare structure
    ->can(EntityAction::all())      // built-in CRUD
    ->can('custom', Handler::class) // custom action
    ->invariant(MyRule::class)      // domain rule
    ->hasMany('orders', 'id_user')  // relation
    ->before('create', fn(...) => ...) // hook
    ->after('create', fn(...) => ...);
```

Virtual entities (no database table) are declared with `->virtual()`, useful for auth, config, or aggregation endpoints.

### EntityAction

Built-in CRUD operations with automatic HTTP method inference:

| Method | HTTP | Route |
|--------|------|-------|
| `EntityAction::create()` | POST | `/api/{entity}` |
| `EntityAction::list()` | GET | `/api/{entity}` |
| `EntityAction::get()` | GET | `/api/{entity}/:id` |
| `EntityAction::update()` | PUT | `/api/{entity}/:id` |
| `EntityAction::delete()` | DELETE | `/api/{entity}/:id` |
| `EntityAction::all()` | All above | All above |

Only declared capabilities exist. If you don't declare `EntityAction::delete()`, the DELETE endpoint won't be generated.

### Field

Declarative, fluent, typed field definitions:

```php
Field::string('name')->required()
Field::email('email')->required()->unique()
Field::password('password')->hidden()
Field::integer('stock')->default(0)
Field::boolean('active')->default(true)
Field::decimal('price')->required()
Field::money('total')
Field::datetime('created_at')->defaultNow()
Field::text('description')
Field::json('metadata')
Field::enum('status', OrderStatus::class)
Field::ref('id_user', 'users')->required()
```

Available types: `id`, `string`, `text`, `integer`, `bigint`, `decimal`, `boolean`, `datetime`, `date`, `email`, `password`, `money`, `json`, `enum`, `ref`.

Modifiers: `required()`, `unique()`, `hidden()`, `nullable()`, `default($v)`, `defaultNow()`, `label($s)`, `max($n)`, `min($n)`.

### Action

Actions are classes with a single `__invoke` method. They receive an `ActionContext` and return a `Result`:

```php
class CreateUserAction
{
    public function __invoke(ActionContext $ctx): Result
    {
        $users = new UserRepository($ctx->repo());

        if ($users->emailExists($ctx->input('email'))) {
            return Result::fail('Email already registered');
        }

        $user = $users->save([
            'name'     => $ctx->input('name'),
            'email'    => $ctx->input('email'),
            'password' => $ctx->hash($ctx->input('password')),
        ]);

        return Result::created(UserResource::make($user));
    }
}
```

**ActionContext methods:**

| Method | Description |
|--------|-------------|
| `input('key')` | Request body value |
| `param('id')` | Route parameter |
| `data()` | All input data |
| `entity()` | Current entity record |
| `userId()` | Authenticated user ID |
| `repo()` | Repository instance |
| `hash($value)` | Hash a password |
| `verifyHash($value, $hash)` | Verify a password |
| `now()` | Current datetime string |
| `dispatch($entity, $action, $payload)` | Cross-entity dispatch |
| `execution()` | ExecutionContext for the dispatch chain |

**Result types:**

| Method | HTTP Status |
|--------|-------------|
| `Result::ok($data)` | 200 |
| `Result::created($data)` | 201 |
| `Result::noContent()` | 204 |
| `Result::fail($message)` | 400 |
| `Result::unauthorized()` | 401 |
| `Result::forbidden()` | 403 |
| `Result::notFound()` | 404 |
| `Result::invalid($message)` | 422 |
| `Result::redirect($url)` | 302 |

### Repository

The `Repository` interface abstracts data persistence. The built-in `PdoRepository` works with any PDO-compatible database.

```php
// Direct usage
$user = $repo->find('users', 42);
$user = $repo->findBy('users', ['email' => 'john@example.com']);
$users = $repo->all('users', ['active' => true]);
$user = $repo->save('users', $data);
$repo->delete('users', 42);
```

**Query builder** for complex queries:

```php
$users = $repo->query()
    ->select('id', 'name', 'email')
    ->from('users')
    ->where('active', true)
    ->where('age', 18, '>=')
    ->orderBy('name')
    ->limit(10)
    ->offset(20)
    ->get();
```

Query methods: `select()`, `from()`, `where()`, `whereIn()`, `orderBy()`, `limit()`, `offset()`, `join()`, `get()`, `first()`, `count()`, `exists()`.

**Custom repositories** wrap the base `Repository` with domain-specific methods:

```php
class UserRepository
{
    public function __construct(private readonly Repository $repo) {}

    public function findByEmail(string $email): ?array
    {
        return $this->repo->findBy('users', ['email' => $email]);
    }

    public function emailExists(string $email): bool
    {
        return $this->repo->query()
            ->from('users')
            ->where('email', $email)
            ->exists();
    }
}
```

### Resource

Resources transform raw data into clean output shapes:

```php
use Nyoze\Domain\Resource;

class UserResource extends Resource
{
    protected function transform(array $data): array
    {
        unset($data['password']);
        return $data;
    }
}

// Single record
UserResource::make($user);

// Collection
UserResource::collection($users);
```

### Rules

Rules enforce domain consistency as entity invariants. They run after every action, ensuring entity state remains coherent.

```php
use Nyoze\Domain\Rule;

class PublishedProjectHasSections extends Rule
{
    public function check(array $data): bool
    {
        if ($data['status'] !== 'published') return true;
        return ($data['section_count'] ?? 0) > 0;
    }

    public function message(): string
    {
        return 'Published projects must have at least one section';
    }
}
```

Register on the entity:

```php
$entity->invariant(PublishedProjectHasSections::class);
```

Inline closures work too:

```php
$entity->invariant(
    fn(array $data) => $data['end_date'] >= $data['start_date'],
    'End date cannot be before start date'
);
```

If a rule fails, Nyoze returns HTTP 422 with the rule's message.

### Hooks

Hooks run logic before or after actions, or react to specific field values:

```php
$entity
    ->before('create', function(mixed $data, ActionContext $ctx) {
        $data['created_at'] = $ctx->now();
        return $data;
    })
    ->after('register', function(mixed $data, ActionContext $ctx) {
        // Send welcome email
    })
    ->when('status', 'published', function(array $data, ActionContext $ctx) {
        // Notify subscribers
    });
```

**Pipeline execution order (custom actions):**

1. Before hooks
2. BEGIN TRANSACTION
3. Action handler
4. Invariants
5. COMMIT
6. When hooks
7. After hooks

Before hooks can modify data (return array) or short-circuit (return `Result`). After hooks are for side effects. When hooks fire on field value matches.

### Cross-Entity Dispatch

When an action needs to invoke logic on another entity, use `$ctx->dispatch()`:

```php
$result = $ctx->dispatch('credits', 'initialize', [
    'id_user' => $user['id'],
]);

if (!$result->success) {
    return $result; // Propagate failure, triggers rollback
}
```

All dispatched actions share the same database transaction. Recursion detection and a max depth of 10 levels prevent runaway chains.

### ExecutionContext

Tracks the full lifecycle of a dispatch chain:

| Method | Description |
|--------|-------------|
| `correlationId()` | Unique ID for this request chain |
| `depth()` | Current nesting depth |
| `callStack()` | Array of entity/action/depth frames |
| `rootEntity()` / `rootAction()` | Root of the chain |
| `currentEntity()` / `currentAction()` | Currently executing |
| `parentEntity()` / `parentAction()` | Parent in the chain |
| `isRoot()` | Whether this is the root action |

## Database

### Configuration

MySQL shortcut:

```php
$app->database()->mysql([
    'host'     => '127.0.0.1',
    'database' => 'my_app',
    'user'     => 'root',
    'pass'     => 'secret',
]);
```

Full fluent API:

```php
use Nyoze\Data\Database\MySqlProvider;
use Nyoze\Data\Database\IdStrategy;

$app->database()
    ->provider(new MySqlProvider())
    ->connection([
        'dsn'  => 'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
        'user' => 'root',
        'pass' => 'secret',
    ])
    ->idStrategy(IdStrategy::Snowflake)
    ->nodeId(1);
```

### ID Strategies

| Strategy | Description |
|----------|-------------|
| `Snowflake` | 64-bit unique IDs (default) |
| `AutoIncrement` | Database auto-increment |
| `Uuid` | UUID v4 |
| `Ulid` | Sortable ULID |

### Providers

Built-in providers: `MySqlProvider` and `SqliteProvider`. Implement `DatabaseProviderInterface` for other databases.

## Migrations

```bash
# Generate migration files from entities
php Nyoze make:migration

# Run pending migrations
php Nyoze migrate

# Rollback last migration
php Nyoze migrate:rollback

# Dump full schema
php Nyoze schema:dump
```

Migrations are versioned SQL files in `database/migrations/`. The `Nyoze_migrations` table tracks execution state.

## CLI

```bash
php vendor/bin/Nyoze
```

| Command | Description |
|---------|-------------|
| `entities` | List all registered entities |
| `schema` | Generate SQL schema (legacy SQLite) |
| `make:migration` | Generate migration files from entities |
| `schema:dump` | Dump full SQL schema using configured provider |
| `migrate` | Run pending migrations |
| `migrate:rollback` | Rollback last migration |

## Framework Structure

```
src/
├── Core/        # Kernel, App, Container
├── Domain/      # Entity, EntityAction, Field, Action, Result, Rule, Resource, Relation
├── Pipeline/    # Action execution engine
├── Data/        # Repository, PdoRepository, Query builder, Schema, Migrations
├── Http/        # Request, Response, HttpEngine (auto-router), Middleware
├── CLI/         # Console, Commands
└── Support/     # Config, Str, Arr
```

## License

MIT, see [LICENSE](LICENSE) for details.
