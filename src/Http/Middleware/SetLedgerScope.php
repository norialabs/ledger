<?php

declare(strict_types=1);

namespace Noria\Ledger\Http\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Noria\Ledger\Access\ScopeResolver;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\LedgerContext;
use Symfony\Component\HttpFoundation\Response;

final class SetLedgerScope
{
    public function __construct(private readonly LedgerContext $context, private readonly Container $container) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->container->bound(ScopeResolver::class)) {
            throw new LedgerException('Configure ledger.scope_resolver or bind '.ScopeResolver::class.' to use the ledger.scope middleware.');
        }
        $resolver = $this->container->make(ScopeResolver::class);
        if (! $resolver instanceof ScopeResolver) {
            throw new LedgerException('The bound ledger scope resolver must implement '.ScopeResolver::class.'.');
        }
        $this->context->set($resolver->resolve());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}
