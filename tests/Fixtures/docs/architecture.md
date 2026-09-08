# Architecture

This library app is organized around `Fixture\Services\Catalog`, which the CatalogController
delegates to for search and checkout. See [models](models.md#book) for how the storage side of
things is laid out.

## Routing

The storefront exposes `books.store` for creating a book and /reports for the tenant report. The
route and the view named `dashboard.index` happen to share the same name, which is exactly the
kind of short name this module refuses to guess about - both are recorded as candidates instead of
picking one.

## Source layout

The book model itself lives at `app/MapinFixture/Models/Book.php`. See
[conventions](#conventions) below for how its table name relates to the author side.

## Conventions

Nothing fancy here yet: `authors` relates to `library_books` one book at a time.
