<?php

namespace App\Exceptions\Stock;

use Symfony\Component\HttpKernel\Exception\HttpException;

class StockUnavailableException extends HttpException
{
    public function __construct(string $message = 'Stock insuffisant pour cette commande.')
    {
        parent::__construct(409, $message);
    }
}
