<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;

/** Fixed, public source diagnostics; never contains Git stderr or credentials. */
final class RemoteProjectSourceException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
