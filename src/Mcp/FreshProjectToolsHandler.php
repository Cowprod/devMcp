<?php
declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Closure;
use Mcp\Capability\Registry\{ElementReference, ReferenceHandler, ReferenceHandlerInterface};
use Mcp\Exception\ToolCallException;
use PDOException;

/** Keep schemas stable, but never reuse an idle SQL connection across tool calls. */
final class FreshProjectToolsHandler implements ReferenceHandlerInterface
{
    public function __construct(private readonly Closure $factory) {}

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        $handler = $reference->handler;
        if (is_array($handler) && $handler[0] instanceof ProjectTools) {
            try {
                $tools = ($this->factory)();
            } catch (PDOException $error) {
                // No SQL text, credentials, arguments or raw exception messages.
                error_log('devmcp registry connection failed: sqlstate=' . $error->getCode()
                    . ' driver_code=' . (int) ($error->errorInfo[1] ?? 0));
                throw new ToolCallException('{"code":"REGISTRY_UNAVAILABLE","message":"Cannot open the project registry. No action was executed; retry when the database is available."}');
            }
            $reference = new ElementReference([$tools, $handler[1]]);
        }
        // Never replay a tool: it may already have queued a job or made a write.
        return (new ReferenceHandler())->handle($reference, $arguments);
    }
}
