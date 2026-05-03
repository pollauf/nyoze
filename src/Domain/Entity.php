<?php

namespace Nyoze\Domain;

use Closure;
use InvalidArgumentException;

/**
 * Entity — the center of the Nyoze framework.
 *
 * Declares structure, capabilities, rules, and relations.
 * Should NOT contain business logic — that goes in Action classes.
 *
 * Entities expose capabilities through ->can(). Everything is an action:
 *   - Built-in CRUD via EntityAction (method inferred automatically)
 *   - Custom actions via string name + handler class (method set explicitly)
 */
class Entity
{
    /** @var FieldDefinition[] */
    private array $fields = [];

    /** @var Action[] */
    private array $actions = [];

    /** @var array<string, list<Closure|string>> */
    private array $beforeHooks = [];

    /** @var array<string, list<Closure|string>> */
    private array $afterHooks = [];

    /** @var array<string, list<array{field: string, value: mixed, handler: Closure|string}>> */
    private array $whenHooks = [];

    /** @var Invariant[] */
    private array $invariants = [];

    /** @var Relation[] */
    private array $relations = [];

    private bool $requiresAuth = false;
    private ?string $ownerField = null;
    private bool $isVirtual = false;

    /** Tracks the last action added, for ->get() / ->post() chaining. */
    private ?string $lastAction = null;

    /** Tracks whether the last action is an entity action (prevents method override). */
    private bool $lastActionIsEntity = false;

    public function __construct(
        public readonly string $name,
    ) {}

    // =========================================================================
    // Fields
    // =========================================================================

    public function fields(FieldBuilder ...$builders): self
    {
        foreach ($builders as $builder) {
            $def = $builder->build();
            $this->fields[$def->name] = $def;
        }
        return $this;
    }

    /** @return FieldDefinition[] */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function getField(string $name): ?FieldDefinition
    {
        return $this->fields[$name] ?? null;
    }

    // =========================================================================
    // Actions (Capabilities)
    // =========================================================================

    /**
     * Declare a capability.
     *
     * Accepts either an EntityAction for built-in CRUD:
     *   ->can(EntityAction::create())
     *   ->can(EntityAction::list())
     *
     * Or an array of EntityAction (from EntityAction::all()):
     *   ->can(EntityAction::all())
     *
     * Or a custom action with explicit handler:
     *   ->can('profile', UserProfileAction::class)->get()
     *   ->can('changePassword', ChangePasswordAction::class)->post()
     */
    public function can(string|EntityAction|array $nameOrAction, Closure|string|null $handler = null, string $method = 'POST'): self
    {
        // EntityAction::all() returns an array
        if (is_array($nameOrAction)) {
            foreach ($nameOrAction as $entityAction) {
                if (!$entityAction instanceof EntityAction) {
                    throw new InvalidArgumentException('Array passed to can() must contain only EntityAction instances.');
                }
                $this->registerEntityAction($entityAction);
            }
            $this->lastAction = null;
            $this->lastActionIsEntity = false;
            return $this;
        }

        // Single EntityAction
        if ($nameOrAction instanceof EntityAction) {
            $this->registerEntityAction($nameOrAction);
            $this->lastAction = $nameOrAction->name;
            $this->lastActionIsEntity = true;
            return $this;
        }

        // Custom action (string name)
        $name = $nameOrAction;

        if (in_array($name, EntityAction::validNames(), true) && $handler !== null) {
            throw new InvalidArgumentException(
                "Action name '{$name}' is reserved for EntityAction. Use EntityAction::{$name}() instead."
            );
        }

        if (isset($this->actions[$name])) {
            throw new InvalidArgumentException(
                "Duplicate action '{$name}' on entity '{$this->name}'."
            );
        }

        $this->actions[$name] = new Action($name, $handler, strtoupper($method), 'custom');
        $this->lastAction = $name;
        $this->lastActionIsEntity = false;
        return $this;
    }

    /** Set last action to GET. */
    public function get(): self
    {
        return $this->via('GET');
    }

    /** Set last action to POST. */
    public function post(): self
    {
        return $this->via('POST');
    }

    /** Set last action to PUT. */
    public function put(): self
    {
        return $this->via('PUT');
    }

    /** Set last action to DELETE. */
    public function delete(): self
    {
        return $this->via('DELETE');
    }

    /** Set HTTP method on the last declared action. */
    private function via(string $method): self
    {
        if ($this->lastActionIsEntity) {
            throw new InvalidArgumentException(
                "Cannot override HTTP method on EntityAction '{$this->lastAction}'. Method is inferred automatically."
            );
        }

        if ($this->lastAction && isset($this->actions[$this->lastAction])) {
            $old = $this->actions[$this->lastAction];
            $this->actions[$this->lastAction] = new Action($old->name, $old->handler, $method, $old->type);
        }
        return $this;
    }

    /**
     * Register a single EntityAction.
     */
    private function registerEntityAction(EntityAction $entityAction): void
    {
        if (isset($this->actions[$entityAction->name])) {
            throw new InvalidArgumentException(
                "Duplicate action '{$entityAction->name}' on entity '{$this->name}'."
            );
        }

        $this->actions[$entityAction->name] = new Action(
            name:       $entityAction->name,
            handler:    null,
            httpMethod: $entityAction->httpMethod,
            type:       'entity',
        );
    }

    /** @return Action[] */
    public function getActions(): array
    {
        return $this->actions;
    }

    public function getAction(string $name): ?Action
    {
        return $this->actions[$name] ?? null;
    }

    /**
     * Check if a specific entity capability is declared.
     */
    public function hasCapability(string $name): bool
    {
        return isset($this->actions[$name]) && $this->actions[$name]->type === 'entity';
    }

    // =========================================================================
    // Hooks
    // =========================================================================

    public function before(string $event, Closure|string $handler): self
    {
        $this->beforeHooks[$event][] = $handler;
        return $this;
    }

    public function after(string $event, Closure|string $handler): self
    {
        $this->afterHooks[$event][] = $handler;
        return $this;
    }

    public function when(string $field, mixed $value, Closure|string $handler): self
    {
        $this->whenHooks[$field][] = [
            'field'   => $field,
            'value'   => $value,
            'handler' => $handler,
        ];
        return $this;
    }

    /** @return list<Closure|string> */
    public function getBeforeHooks(string $event): array
    {
        return $this->beforeHooks[$event] ?? [];
    }

    /** @return list<Closure|string> */
    public function getAfterHooks(string $event): array
    {
        return $this->afterHooks[$event] ?? [];
    }

    public function getWhenHooks(string $field): array
    {
        return $this->whenHooks[$field] ?? [];
    }

    // =========================================================================
    // Invariants
    // =========================================================================

    /**
     * @param Closure|string $checker  Closure, Rule class, or callable class
     */
    public function invariant(Closure|string $checker, string $message = 'Invariant violated'): self
    {
        // Auto-extract message from Rule classes
        if (is_string($checker) && class_exists($checker)) {
            $instance = new $checker();
            if ($instance instanceof Rule) {
                $message = $instance->message();
            }
        }

        $this->invariants[] = new Invariant($checker, $message);
        return $this;
    }

    /** @return Invariant[] */
    public function getInvariants(): array
    {
        return $this->invariants;
    }

    // =========================================================================
    // Relations
    // =========================================================================

    public function hasMany(string $entity, string $foreignKey, ?string $localKey = 'id'): self
    {
        $this->relations[$entity] = new Relation($entity, $entity, RelationType::HasMany, $foreignKey, $localKey);
        return $this;
    }

    public function hasOne(string $entity, string $foreignKey, ?string $localKey = 'id'): self
    {
        $this->relations[$entity] = new Relation($entity, $entity, RelationType::HasOne, $foreignKey, $localKey);
        return $this;
    }

    public function belongsTo(string $entity, string $foreignKey, ?string $localKey = 'id'): self
    {
        $this->relations[$entity] = new Relation($entity, $entity, RelationType::BelongsTo, $foreignKey, $localKey);
        return $this;
    }

    /** @return Relation[] */
    public function getRelations(): array
    {
        return $this->relations;
    }

    // =========================================================================
    // Identity & Configuration
    // =========================================================================

    public function auth(): self
    {
        $this->requiresAuth = true;
        return $this;
    }

    public function ownedBy(string $field): self
    {
        $this->ownerField   = $field;
        $this->requiresAuth = true;
        return $this;
    }

    public function virtual(): self
    {
        $this->isVirtual = true;
        return $this;
    }

    public function needsAuth(): bool
    {
        return $this->requiresAuth;
    }

    public function getOwnerField(): ?string
    {
        return $this->ownerField;
    }

    public function isVirtual(): bool
    {
        return $this->isVirtual;
    }
}
