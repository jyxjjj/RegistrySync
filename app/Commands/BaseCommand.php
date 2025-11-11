<?php

namespace App\Commands;

use App\Common\RequestHelper;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Throwable;

abstract class BaseCommand extends Command
{
    protected ?string $MESSAGE_ENDPOINT_URL;
    protected ?string $MESSAGE_TOKEN_NAME;
    protected ?string $MESSAGE_TOKEN;
    protected array $MESSAGES;

    public function __construct()
    {
        parent::__construct();
        $this->MESSAGES = [];
        $this->MESSAGE_ENDPOINT_URL = env('MESSAGE_ENDPOINT_URL');
        $this->MESSAGE_TOKEN_NAME = env('MESSAGE_TOKEN_NAME');
        $this->MESSAGE_TOKEN = env('MESSAGE_TOKEN');
    }

    protected function ansiError(string $message): void
    {
        $time = Carbon::now()->setTimezone('Etc/GMT-8')->format('Y-m-d H:i:s.v');
        $formatted = "[$time] \033[31m$message\033[0m\n";
        fwrite(STDERR, $formatted);
    }

    protected function ansiInfo(string $message): void
    {
        $time = Carbon::now()->setTimezone('Etc/GMT-8')->format('Y-m-d H:i:s.v');
        $formatted = "[$time] \033[34m$message\033[0m\n";
        fwrite(STDOUT, $formatted);
    }

    protected function ansiSuccess(string $message): void
    {
        $time = Carbon::now()->setTimezone('Etc/GMT-8')->format('Y-m-d H:i:s.v');
        $formatted = "[$time] \033[32m$message\033[0m\n";
        fwrite(STDOUT, $formatted);
    }

    protected function echo(string $message): void
    {
        $time = Carbon::now()->setTimezone('Etc/GMT-8')->format('Y-m-d H:i:s.v');
        $formatted = "[$time] $message\n";
        fwrite(STDOUT, $formatted);
    }

    protected function pushNotification(string $message): void
    {
        $this->MESSAGES[] = $message;
    }

    protected function sendNotification(): bool
    {
        try {
            $messages = implode("\n", $this->MESSAGES);
            RequestHelper::getInstance(retry: 10, retryDelay: 100)
                ->withHeaders([
                    $this->MESSAGE_TOKEN_NAME => $this->MESSAGE_TOKEN,
                ])
                ->post($this->MESSAGE_ENDPOINT_URL, ['text' => "<blockquote><code>$messages</code></blockquote>"]);
        } catch (Throwable) {
            return false;
        }
        return true;
    }
}
