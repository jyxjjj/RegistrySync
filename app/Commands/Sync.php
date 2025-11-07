<?php

namespace App\Commands;

use App\Common\RequestHelper;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class Sync extends Command
{
    protected $signature = 'sync';
    protected $description = 'Sync container registries based on configuration';

    private string $DESTINATION_REGISTRY;
    private array $IMAGES = [
        'docker.io library/fedora latest 43',
        'docker.io library/mariadb lts [VERSION]',
        'docker.io library/redis latest [VERSION]',
        'docker.io library/postgres latest [VERSION]',
        'docker.io dpage/pgadmin4 latest [VERSION]',
        'docker.io openlistteam/openlist beta',
        'docker.io openlistteam/openlist latest [VERSION]',
        'docker.io adguard/adguardhome latest [VERSION]',
    ];
    private string $MESSAGE_ENDPOINT_URL;
    private string $MESSAGE_TOKEN_NAME;
    private string $MESSAGE_TOKEN;
    private array $MESSAGES;

    public function handle(): int
    {
        $this->MESSAGE_ENDPOINT_URL = env('MESSAGE_ENDPOINT_URL');
        $this->MESSAGE_TOKEN_NAME = env('MESSAGE_TOKEN_NAME');
        $this->MESSAGE_TOKEN = env('MESSAGE_TOKEN');
        $this->DESTINATION_REGISTRY = env('REGISTRY_URL');
        $check = $this->checkURL($this->DESTINATION_REGISTRY);
        if (!$check) {
            $this->ansiError('Registry URL check failed');
            return self::FAILURE;
        }
        $this->fetchImages();
        foreach ($this->IMAGES as $image) {
            echo str_repeat('=', 64) . "\n";
            $D = explode(' ', $image);
            switch (count($D)) {
                case 3:
                    [$REGISTRY, $IMAGE_NAME, $IMAGE_TAG] = $D;
                    $this->syncImageTag($REGISTRY, $IMAGE_NAME, $IMAGE_TAG);
                    break;
                case 4:
                    [$REGISTRY, $IMAGE_NAME, $IMAGE_TAG, $IMAGE_VERSION] = $D;
                    if ($this->checkImage($REGISTRY, $IMAGE_NAME, $IMAGE_TAG, $IMAGE_VERSION)) {
                        $this->syncImageTag($REGISTRY, $IMAGE_NAME, $IMAGE_TAG);
                        $this->syncImageTag($REGISTRY, $IMAGE_NAME, $IMAGE_VERSION);
                    } else {
                        $this->ansiError("Failed to sync $IMAGE_NAME");
                    }
                    break;
                default:
                    $this->ansiError("Invalid image configuration: $image");
                    echo str_repeat('=', 64) . "\n";
                    return self::INVALID;
            }
            echo str_repeat('=', 64) . "\n";
        }
        $start = Carbon::createFromFormat('U.u', LARAVEL_START)->setTimezone('Etc/GMT-8');
        $end = Carbon::now()->setTimezone('Etc/GMT-8');
        $duration = $start->diffInMilliseconds($end);
        $this->ansiInfo("Job completed, duration: $duration ms.");
        $this->pushNotification("Total duration: $duration ms.");
        $this->sendNotification();
        return self::SUCCESS;
    }

    private function ansiError(string $message): void
    {
        $time = Carbon::now()->setTimezone('Etc/GMT-8')->format('Y-m-d H:i:s.v');
        $formatted = "[$time] \033[31m$message\033[0m\n";
        fwrite(STDERR, $formatted);
    }

    private function ansiInfo(string $message): void
    {
        $time = Carbon::now()->setTimezone('Etc/GMT-8')->format('Y-m-d H:i:s.v');
        $formatted = "[$time] \033[34m$message\033[0m\n";
        fwrite(STDOUT, $formatted);
    }

    private function ansiSuccess(string $message): void
    {
        $time = Carbon::now()->setTimezone('Etc/GMT-8')->format('Y-m-d H:i:s.v');
        $formatted = "[$time] \033[32m$message\033[0m\n";
        fwrite(STDOUT, $formatted);
    }

    private function echo(string $message): void
    {
        $time = Carbon::now()->setTimezone('Etc/GMT-8')->format('Y-m-d H:i:s.v');
        $formatted = "[$time] $message\n";
        fwrite(STDOUT, $formatted);
    }

    private function pushNotification(string $message): void
    {
        $this->MESSAGES[] = $message;
    }

    private function sendNotification(): bool
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

    private function skopeo(string $args): ProcessResult
    {
        echo "> skopeo $args\n";
        $args = explode(' ', "skopeo $args");
        try {
            $command = Process::newPendingProcess()->timeout(300);
            $result = $command->run($args, function (string $type, string $buffer) use ($args) {
                if ($args[1] == 'copy') {
                    if ($type === 'stdout') {
                        fwrite(STDOUT, $buffer);
                    } else {
                        fwrite(STDERR, $buffer);
                    }
                }
            });
        } catch (Throwable $e) {
            $this->ansiError('Error executing skopeo command: ' . $e->getMessage());
            return Process::run('exit 1');
        }
        return $result;
    }

    private function checkURL(string $url): bool
    {
        try {
            $resp = RequestHelper::getInstance(10, 30)->get("https://$url/v2/_catalog");
            $statusCode = $resp->status();
            if ($statusCode != 200) {
                return false;
            } else {
                return true;
            }
        } catch (Throwable) {
            return false;
        }
    }

    private function sortTags(array $tags): array
    {
        foreach ($tags as $id => $tag) {
            if (!preg_match('/^v?[0-9]+(\.[0-9]+){0,2}$/', $tag)) {
                unset($tags[$id]);
            }
        }
        $tags = array_values($tags);
        usort($tags, fn($a, $b) => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));
        return $tags;
    }

    private function getDigestOf(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_TAG): string
    {
        $result = $this->skopeo("inspect --override-arch amd64 --override-os linux docker://$REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        if ($result->successful()) {
            return json_decode($result->output(), true)['Digest'] ?? '';
        } else {
            $this->ansiError("Failed to fetch digest for $IMAGE_NAME:$IMAGE_TAG from $REGISTRY.");
            return '';
        }
    }

    private function getVerionOf(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_TAG): string
    {
        $this->ansiInfo("Determining version for $IMAGE_NAME with tag $IMAGE_TAG...");
        $targetDigest = $this->getDigestOf($REGISTRY, $IMAGE_NAME, $IMAGE_TAG);
        if (empty($targetDigest)) {
            $this->ansiError("Could not determine digest for $IMAGE_NAME with tag $IMAGE_TAG.");
            return '';
        }
        $this->ansiInfo("Target digest for $IMAGE_NAME:$IMAGE_TAG is $targetDigest");
        $this->ansiInfo("Fetching all tags for $IMAGE_NAME from $REGISTRY to find matching digest...");
        $IMAGE_ALL_TAGS = $this->skopeo("list-tags docker://$REGISTRY/$IMAGE_NAME");
        if ($IMAGE_ALL_TAGS->successful()) {
            $tagsJson = $IMAGE_ALL_TAGS->output();
            $tags = json_decode($tagsJson, true)['Tags'] ?? [];
            $tags = $this->sortTags($tags);
            foreach ($tags as $tag) {
                $digest = $this->getDigestOf($REGISTRY, $IMAGE_NAME, $tag);
                if ($digest === $targetDigest) {
                    return $tag;
                }
            }
        } else {
            $this->ansiError("Failed to fetch tags for $IMAGE_NAME from $REGISTRY.");
        }
        return '';
    }

    private function fetchImages(): void
    {
        foreach ($this->IMAGES as $id => &$image) {
            if (str_contains($image, '[VERSION]')) {
                $D = explode(' ', $image);
                [$REGISTRY, $IMAGE_NAME, $IMAGE_TAG,] = $D;
                $IMAGE_VERSION = $this->getVerionOf($REGISTRY, $IMAGE_NAME, $IMAGE_TAG);
                if (empty($IMAGE_VERSION)) {
                    $this->ansiError("Could not determine version for $IMAGE_NAME with tag $IMAGE_TAG.");
                    unset($this->IMAGES[$id]);
                    continue;
                }
                $image = str_replace('[VERSION]', $IMAGE_VERSION, $image);
            }
        }
        $this->IMAGES = array_values($this->IMAGES);
    }

    private function checkImage(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_TAG, string $IMAGE_VERSION): bool
    {
        $LResult = $this->skopeo("inspect --override-arch amd64 --override-os linux docker://$REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        if ($LResult->successful()) {
            $L = json_decode($LResult->output(), true)['Digest'];
        } else {
            $this->ansiError("Failed to fetch $IMAGE_NAME:$IMAGE_TAG.");
            return false;
        }
        $RResult = $this->skopeo("inspect --override-arch amd64 --override-os linux docker://$REGISTRY/$IMAGE_NAME:$IMAGE_VERSION");
        if ($RResult->successful()) {
            $R = json_decode($RResult->output(), true)['Digest'];
        } else {
            $this->ansiError("Failed to fetch $IMAGE_NAME:$IMAGE_VERSION.");
            return false;
        }
        if ($L === $R) {
            $this->ansiSuccess("$IMAGE_NAME:$IMAGE_VERSION is up to date.");
            return true;
        } else {
            $this->ansiError("$IMAGE_NAME:$IMAGE_VERSION is outdated.");
            return false;
        }
    }

    private function syncImageTag(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_TAG): void
    {
        $start = Carbon::now();
        $this->ansiInfo("Syncing image: $REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        $result = $this->skopeo("copy --dest-precompute-digests --preserve-digests --retry-times 10 --override-arch amd64 --override-os linux docker://$REGISTRY/$IMAGE_NAME:$IMAGE_TAG docker://$this->DESTINATION_REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        if ($result->successful()) {
            $str = '✅';
            $this->ansiSuccess("Successfully synced image: $REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        } else {
            $str = '❌';
            $this->ansiError("Failed to sync image: $REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        }
        $end = Carbon::now();
        $duration = $start->diffInMilliseconds($end);
        $this->echo("Sync completed, duration: $duration ms");
        $name = $REGISTRY === 'docker.io' ? str_replace('library/', '', "$IMAGE_NAME:$IMAGE_TAG") : "$REGISTRY/$IMAGE_NAME:$IMAGE_TAG";
        $this->pushNotification("$str $name => $duration ms.");
    }
}
