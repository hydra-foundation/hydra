<?php

declare(strict_types=1);

namespace Hydra\Http;

use Closure;
use Hydra\Http\Contracts\ArgumentResolverInterface;
use Hydra\Http\Exceptions\NotFoundException;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Default resolver: reflects the target's signature and fills each parameter
 */
final class ArgumentResolver implements ArgumentResolverInterface
{
    public function resolve(callable $target, ServerRequestInterface $request, array $routeParams): array
    {
        $reflection = new ReflectionFunction(Closure::fromCallable($target));

        $args = [];
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();

            // Rule 1: the request, matched by type so it can be named anything.
            if (
                $type instanceof ReflectionNamedType
                && !$type->isBuiltin()
                && $request instanceof ($type->getName())
            ) {
                $args[] = $request;
                continue;
            }

            // Rule 2: a route placeholder, coerced to the declared scalar type.
            $name = $param->getName();
            if (array_key_exists($name, $routeParams)) {
                $args[] = $this->coerce($routeParams[$name], $type, $param);
                continue;
            }

            // Rule 3: fall back to a default, then to null for a nullable param.
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            if ($type !== null && $type->allowsNull()) {
                $args[] = null;
                continue;
            }

            // Rule 4: nothing can supply this parameter.
            throw new LogicException(sprintf(
                'Cannot resolve parameter $%s for %s: it is neither the request, '
                    . 'a route parameter, nor optional.',
                $name,
                $this->describe($reflection)
            ));
        }

        return $args;
    }

    /**
     * Coerce a matched route value to its declared scalar type. A value that
     * doesn't fit (bad int, unrecognised bool) is a client-side non-match and
     * raises NotFoundException; a type we can't coerce at all (e.g. an array or
     * class hint on a placeholder) is a wiring bug and raises LogicException.
     */
    private function coerce(string $value, mixed $type, ReflectionParameter $param): mixed
    {
        // Untyped, mixed, or string: the raw segment is exactly what's wanted.
        if (!$type instanceof ReflectionNamedType) {
            // A union/intersection type on a placeholder is ambiguous to coerce.
            if ($type === null) {
                return $value;
            }
            throw $this->unsupportedType($param);
        }

        return match ($type->getName()) {
            'string', 'mixed' => $value,
            'int' => $this->toInt($value),
            'float' => $this->toFloat($value),
            'bool' => $this->toBool($value),
            default => throw $this->unsupportedType($param),
        };
    }

    private function toInt(string $value): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT);

        return $result === false ? throw new NotFoundException('') : $result;
    }

    private function toFloat(string $value): float
    {
        $result = filter_var($value, FILTER_VALIDATE_FLOAT);

        return $result === false ? throw new NotFoundException('') : $result;
    }

    /** Path segments should be canonical, so only the obvious tokens are accepted. */
    private function toBool(string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new NotFoundException(''),
        };
    }

    private function unsupportedType(ReflectionParameter $param): LogicException
    {
        $type = $param->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

        return new LogicException(sprintf(
            'Route parameter $%s is typed %s, which cannot be coerced from a URL segment.',
            $param->getName(),
            $name
        ));
    }

    private function describe(ReflectionFunction $reflection): string
    {
        $scope = $reflection->getClosureScopeClass();

        return $scope !== null
            ? $scope->getName() . '::' . $reflection->getName() . '()'
            : $reflection->getName() . '()';
    }
}
