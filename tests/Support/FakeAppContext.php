<?php

declare(strict_types=1);

namespace WinterDoctrineTest\Support;

use dev\winterframework\core\context\ApplicationContext;
use LogicException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Builds an ApplicationContext that only answers bean-by-class lookups
 * from a fixed map. Every other method throws. The stub class is generated
 * from the interface, so it keeps compiling when winter-boot adds methods.
 */
final class FakeAppContext {

    /** @var array<string, object> */
    public static array $beans = [];

    public static function create(array $beansByClass): ApplicationContext {
        self::$beans = $beansByClass;
        $class = 'WinterDoctrineTest\Support\GeneratedFakeAppContext';
        if (!class_exists($class, false)) {
            eval(self::generate());
        }
        return new $class();
    }

    private static function typeName(ReflectionType $type): string {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->isBuiltin() || in_array($type->getName(), ['self', 'static'], true)
                ? $type->getName()
                : '\\' . $type->getName();
            return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '') . $name;
        }
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn($t) => self::typeName($t), $type->getTypes()));
        }
        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn($t) => self::typeName($t), $type->getTypes()));
        }
        return (string)$type;
    }

    private static function generate(): string {
        $ref = new ReflectionClass(ApplicationContext::class);
        $methods = [];
        foreach ($ref->getMethods() as $m) {
            $params = [];
            foreach ($m->getParameters() as $p) {
                $type = $p->getType() ? self::typeName($p->getType()) . ' ' : '';
                $default = $p->isDefaultValueAvailable()
                    ? ' = ' . var_export($p->getDefaultValue(), true)
                    : ($p->allowsNull() && $p->getType() ? ' = null' : '');
                $params[] = $type . ($p->isVariadic() ? '...' : '') . '$' . $p->getName() . $default;
            }
            $ret = $m->getReturnType();
            $retDecl = $ret ? ': ' . self::typeName($ret) : '';
            $body = match ($m->getName()) {
                'hasBeanByClass' => 'return isset(\\' . self::class . '::$beans[$' . $m->getParameters()[0]->getName() . ']);',
                'beanByClass' => 'return \\' . self::class . '::$beans[$' . $m->getParameters()[0]->getName() . '];',
                default => 'throw new \\' . LogicException::class . '("' . $m->getName() . ' not faked");',
            };
            if ($ret instanceof ReflectionNamedType && $ret->getName() === 'void' && str_starts_with($body, 'return')) {
                $body = 'throw new \\' . LogicException::class . '("not faked");';
            }
            $methods[] = 'public function ' . $m->getName() . '(' . implode(', ', $params) . ')' . $retDecl . ' { ' . $body . ' }';
        }
        return 'namespace WinterDoctrineTest\Support; final class GeneratedFakeAppContext implements \\'
            . ApplicationContext::class . ' { ' . implode("\n", $methods) . ' }';
    }
}
