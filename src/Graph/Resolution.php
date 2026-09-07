<?php

declare(strict_types=1);

namespace Mapin\Graph;

/**
 * How a reference's target was determined. Confidence is the ceiling for this kind in isolation;
 * a value carried through a chain (a local assignment, a relation forwarded to a related model's
 * builder, a facade's own docblock) is capped at the minimum of its own kind and every kind it
 * passed through, per SPEC.md section 3.3.
 */
enum Resolution: string
{
    case StaticCall = 'static';
    case NewInstance = 'new';
    case SelfRef = 'self';
    case ParentRef = 'parent';
    case ThisRef = 'this';
    case TypedProperty = 'typed_property';
    case Param = 'param';
    case Container = 'container';
    case ConstructorAssignment = 'constructor_assignment';
    case LocalAssign = 'local_assign';
    case ReturnType = 'return_type';
    case Facade = 'facade';
    case Cast = 'cast';
    case Catch_ = 'catch';
    case Eloquent = 'eloquent';
    case Docblock = 'docblock';
    case Interface_ = 'interface';
    case UniqueMember = 'unique_member';
    case Unresolved = 'unresolved';

    public function baseConfidence(): float
    {
        return match ($this) {
            self::StaticCall, self::NewInstance, self::SelfRef, self::ParentRef, self::ThisRef,
            self::TypedProperty, self::Param, self::Container, self::Catch_ => 1.0,
            self::ConstructorAssignment, self::LocalAssign, self::ReturnType,
            self::Facade, self::Cast => 0.8,
            self::Eloquent, self::Docblock => 0.7,
            self::Interface_ => 0.6,
            self::UniqueMember => 0.4,
            self::Unresolved => 0.0,
        };
    }
}
