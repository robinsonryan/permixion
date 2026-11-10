<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion;

/**
 * What a holder may do in one scope: a set of permission names and wildcard
 * patterns, or "everything" (an uncapped super admin).
 *
 * Intersection is how a cap applies. Patterns are prefix wildcards (`posts.*`),
 * so the intersection of two patterns is the narrower one when one covers the
 * other and empty otherwise; keeping every member of either side that the other
 * side covers is therefore exact — a name is granted by the intersection if and
 * only if both sides grant it.
 */
final readonly class EffectivePermissions
{
    /**
     * @param  array<int, string>  $patterns
     */
    private function __construct(
        private bool $unlimited,
        private array $patterns,
    ) {}

    public static function none(): self
    {
        return new self(false, []);
    }

    public static function unlimited(): self
    {
        return new self(true, []);
    }

    /**
     * @param  array<int, string>  $patterns
     */
    public static function of(array $patterns): self
    {
        return new self(false, array_values(array_unique($patterns)));
    }

    public function isUnlimited(): bool
    {
        return $this->unlimited;
    }

    public function isEmpty(): bool
    {
        return ! $this->unlimited && $this->patterns === [];
    }

    public function grants(string $permission): bool
    {
        return $this->unlimited || $this->covers($this->patterns, $permission);
    }

    /**
     * The stored names and patterns. Empty for an unlimited set, which is not a
     * list; ask isUnlimited() first.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /**
     * Whether this set grants everything $other grants. Only an unlimited set
     * includes an unlimited one.
     */
    public function includes(self $other): bool
    {
        if ($this->unlimited) {
            return true;
        }

        if ($other->unlimited) {
            return false;
        }

        foreach ($other->patterns as $pattern) {
            if (! $this->covers($this->patterns, $pattern)) {
                return false;
            }
        }

        return true;
    }

    public function union(self $other): self
    {
        if ($this->unlimited || $other->unlimited) {
            return self::unlimited();
        }

        return self::of([...$this->patterns, ...$other->patterns]);
    }

    public function intersect(self $other): self
    {
        if ($this->unlimited) {
            return $other;
        }

        if ($other->unlimited) {
            return $this;
        }

        $kept = [];

        foreach ($this->patterns as $pattern) {
            if ($this->covers($other->patterns, $pattern)) {
                $kept[] = $pattern;
            }
        }

        foreach ($other->patterns as $pattern) {
            if ($this->covers($this->patterns, $pattern)) {
                $kept[] = $pattern;
            }
        }

        return self::of($kept);
    }

    /**
     * Whether any of $patterns grants $name. $name may itself be a wildcard, in
     * which case a broader wildcard covers it (`posts.*` covers `posts.drafts.*`).
     *
     * @param  array<int, string>  $patterns
     */
    private function covers(array $patterns, string $name): bool
    {
        $permixion = app(Permixion::class);

        foreach ($patterns as $pattern) {
            if ($permixion->permissionMatches($name, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
