# Rule: Pure PHP

- PHP 8.3 only. No Laravel, Symfony, CodeIgniter, Slim, or any full framework.
- Simple class-based structure; constructor injection preferred; small service classes over god objects.
- declare(strict_types=1) and typed properties where useful.
- Central error handler; no silent catches; errors logged, never exposed raw to users.
- Minimal dependencies: standard library + ext-pdo_sqlite + ext-curl preferred over packages. Any Composer package requires explicit approval.
