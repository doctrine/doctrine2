<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalExtensions;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as BaseConnection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;

class Connection extends BaseConnection
{
    public QueryLog $queryLog;

    public function __construct(array $params, Driver $driver, Configuration|null $config = null)
    {
        $this->queryLog = new QueryLog();
        $logging        = new LoggingMiddleware(new SqlLogger($this->queryLog));
        $driver         = $logging->wrap($driver);

        parent::__construct($params, $driver, $config);
    }
}
