<?php

declare(strict_types=1);

// Stand-ins for an app's models and the relations they declare. Only the shape matters to MorphDiscovery: the model
// has getTable()/getMorphClass()/getKeyName(), a relation method returns something that answers like Laravel's
// MorphMany / MorphOne / MorphToMany. No framework is needed.

namespace PeakSim\Runtime\Tests\Stubs\Relations {
    class MorphMany
    {
        public function __construct(private readonly string $related, private readonly string $type, private readonly string $id, private readonly string $localKey = 'id')
        {
        }

        public function getRelated(): object
        {
            return new ($this->related)();
        }

        public function getMorphType(): string
        {
            return $this->related::table() . '.' . $this->type; // Laravel returns the qualified name
        }

        public function getForeignKeyName(): string
        {
            return $this->id;
        }

        public function getLocalKeyName(): string
        {
            return $this->localKey;
        }
    }

    final class MorphOne extends MorphMany
    {
    }

    final class MorphToMany
    {
        /** @param ?class-string $related the model on the other side; null: this relation does not say */
        public function __construct(private readonly string $pivot, private readonly string $type, private readonly string $id, private readonly ?string $related = null, private readonly string $relatedPivotKey = '')
        {
        }

        public function getRelated(): object
        {
            return $this->related === null ? throw new \LogicException('this relation does not say what it relates to') : new ($this->related)();
        }

        public function getRelatedPivotKeyName(): string
        {
            return $this->relatedPivotKey;
        }

        public function getRelatedKeyName(): string
        {
            return 'id';
        }

        public function getTable(): string
        {
            return $this->pivot;
        }

        public function getMorphType(): string
        {
            return $this->pivot . '.' . $this->type;
        }

        public function getForeignPivotKeyName(): string
        {
            return $this->id;
        }

        public function getParentKeyName(): string
        {
            return 'id';
        }
    }

    final class HasMany
    {
    }
}

namespace PeakSim\Runtime\Tests\Stubs\Models {
    use PeakSim\Runtime\Tests\Stubs\Relations\HasMany;
    use PeakSim\Runtime\Tests\Stubs\Relations\MorphMany;
    use PeakSim\Runtime\Tests\Stubs\Relations\MorphOne;
    use PeakSim\Runtime\Tests\Stubs\Relations\MorphToMany;

    abstract class Base
    {
        public function getKeyName(): string
        {
            return 'id';
        }

        public function getMorphClass(): string
        {
            return static::class;
        }

        public function getTable(): string
        {
            return static::table();
        }

        abstract public static function table(): string;
    }

    final class Attachment extends Base
    {
        public static function table(): string
        {
            return 'attachments';
        }
    }

    final class Location extends Base
    {
        public static function table(): string
        {
            return 'locations';
        }
    }

    final class Tag extends Base
    {
        public static function table(): string
        {
            return 'tags';
        }
    }

    final class Bill extends Base
    {
        public static function table(): string
        {
            return 'bills';
        }

        public function attachments(): MorphMany
        {
            return new MorphMany(Attachment::class, 'attachable_type', 'attachable_id');
        }

        public function location(): MorphOne
        {
            return new MorphOne(Location::class, 'locatable_type', 'locatable_id');
        }

        public function tags(): MorphToMany
        {
            return new MorphToMany('taggables', 'taggable_type', 'taggable_id', Tag::class, 'tag_id');
        }

        public function labels(): MorphToMany
        {
            return new MorphToMany('labelables', 'labelable_type', 'labelable_id');
        }

        public function plain(): HasMany
        {
            return new HasMany();
        }

        public function withArgument(int $x): MorphMany
        {
            return new MorphMany(Attachment::class, 'attachable_type', 'attachable_id');
        }

        public function broken(): MorphMany
        {
            throw new \RuntimeException('a relation that cannot be built');
        }

        public static function make(): MorphMany
        {
            return new MorphMany(Attachment::class, 'attachable_type', 'attachable_id');
        }
    }

    final class Note extends Base
    {
        public static function table(): string
        {
            return 'notes';
        }

        public function attachments(): MorphMany
        {
            return new MorphMany(Attachment::class, 'attachable_type', 'attachable_id');
        }
    }
}
