<?php

declare(strict_types=1);

namespace Mapin\Graph;

enum NodeType: string
{
    case File = 'file';
    case ClassLike = 'class';
    case Method = 'method';
    case Function_ = 'function';
    case Route = 'route';
    case View = 'view';
    case Component = 'component';
    case Middleware = 'middleware';
    case Table = 'table';
    case Doc = 'doc';
    case Section = 'section';
    case Concept = 'concept';
    case External = 'external';
}
