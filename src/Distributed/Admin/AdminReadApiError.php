<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;
final class AdminReadApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode) { parent::__construct($errorCode); }
}
