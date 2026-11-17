<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Android;
final class DiagnosticException extends \RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}
