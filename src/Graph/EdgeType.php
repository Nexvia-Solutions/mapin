<?php

declare(strict_types=1);

namespace Mapin\Graph;

enum EdgeType: string
{
    case Declares = 'declares';
    case Extends = 'extends';
    case Implements = 'implements';
    case UsesTrait = 'uses_trait';
    case Calls = 'calls';
    case Instantiates = 'instantiates';
    case Injects = 'injects';
    case Binds = 'binds';
    case Resolves = 'resolves';
    case RoutesTo = 'routes_to';
    case UsesMiddleware = 'uses_middleware';
    case Renders = 'renders';
    case Includes = 'includes';
    case UsesComponent = 'uses_component';
    case LinksRoute = 'links_route';
    case Relates = 'relates';
    case MapsTable = 'maps_table';
    case TouchesTable = 'touches_table';
    case Dispatches = 'dispatches';
    case Listens = 'listens';
    case Observes = 'observes';
    case Schedules = 'schedules';
    case Documents = 'documents';
    case LinksDoc = 'links_doc';
    case Mentions = 'mentions';
    case RelatesConcept = 'relates_concept';
    case Requests = 'requests';
}
