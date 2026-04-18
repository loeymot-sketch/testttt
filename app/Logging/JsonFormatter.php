<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;

class JsonFormatter extends MonologJsonFormatter
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function format(array $record): string
    {
        $data = [
            'timestamp' => $record['datetime']->format('c'),
            'level' => $record['level_name'],
            'message' => $record['message'],
            'context' => $record['context'] ?? [],
            'extra' => $record['extra'] ?? [],
            'channel' => $record['channel'] ?? 'app',
        ];

        return $this->toJson($data)."\n";
    }
}
