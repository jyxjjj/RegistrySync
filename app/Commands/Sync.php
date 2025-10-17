<?php

namespace App\Commands;

use App\Common\RequestHelper;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class Sync extends Command
{
    protected $signature = 'sync';
    protected $description = 'Sync container registries based on configuration';

    private string $DESTINATION_REGISTRY;
    private array $IMAGES = [
        'docker.io library/fedora latest 42',
        'docker.io library/mariadb lts [VERSION]',
        'docker.io library/redis latest [VERSION]',
        'docker.io library/postgres latest [VERSION]',
        'docker.io dpage/pgadmin4 latest [VERSION]',
        'docker.io openlistteam/openlist beta',
        'docker.io openlistteam/openlist latest [VERSION]',
        'docker.io adguard/adguardhome latest [VERSION]',
    ];

    public function handle(): int
    {
        $REGISTRY_URL = env('REGISTRY_URL');
        $this->DESTINATION_REGISTRY = $REGISTRY_URL;
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
                    $this->checkAndSyncImage($REGISTRY, $IMAGE_NAME, $IMAGE_TAG, $IMAGE_VERSION);
                    break;
                default:
                    $this->ansiError("Invalid image configuration: $image");
                    echo str_repeat('-', 64) . "\n";
                    return self::INVALID;
            };
            echo str_repeat('=', 64) . "\n";
        }
        $this->ansiInfo('Job completed, all images have been processed.');
        return self::SUCCESS;
    }

    private function ansiError(string $message): void
    {
        $this->ansiOutput($message, '31', true);
    }

    private function ansiInfo(string $message): void
    {
        $this->ansiOutput($message, '34', false);
    }

    private function ansiOutput(string $message, string $colorCode, bool $useStderr = false): void
    {
        $formatted = "\033[{$colorCode}m{$message}\033[0m\n";
        if ($useStderr) {
            fwrite(STDERR, $formatted);
        } else {
            fwrite(STDOUT, $formatted);
        }
    }

    private function ansiSuccess(string $message): void
    {
        $this->ansiOutput($message, '32', false);
    }

    private function checkAndSyncImage(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_TAG, ?string $IMAGE_VERSION = null): void
    {
        if ($this->checkImage($REGISTRY, $IMAGE_NAME, $IMAGE_TAG, $IMAGE_VERSION)) {
            $this->syncImage($REGISTRY, $IMAGE_NAME, $IMAGE_TAG, $IMAGE_VERSION);
        } else {
            $this->ansiError("Failed to sync $IMAGE_NAME");
        }
    }

    private function checkImage(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_TAG, ?string $IMAGE_VERSION = null): bool
    {
        if ($IMAGE_TAG === 'null' || $IMAGE_TAG === null) {
            $this->ansiSuccess("$IMAGE_NAME:$IMAGE_VERSION is up to date.");
        }
        $L = $R = '';
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

    private function getDigestOf(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_TAG): string
    {
        $result = $this->skopeo("inspect --override-arch amd64 --override-os linux docker://$REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        if ($result->successful()) {
            $digest = json_decode($result->output(), true)['Digest'] ?? '';
            return $digest;
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
            return '';
        } else {
            $this->ansiError("Failed to fetch tags for $IMAGE_NAME from $REGISTRY.");
            return '';
        }
    }

    private function skopeo(string $args): ProcessResult
    {
        echo "> skopeo $args\n";
        $args = explode(' ', "skopeo $args");
        $command = Process::newPendingProcess();
        $result = $command->run($args, function (string $type, string $buffer) use ($args) {
            if ($args[1] == 'copy') {
                if ($type === 'stdout') {
                    fwrite(STDOUT, $buffer);
                } else {
                    fwrite(STDERR, $buffer);
                }
            }
        });
        return $result;
    }

    private function sortTags(array $tags): array
    {
        foreach ($tags as $id => $tag) {
            if (!preg_match('/^v?[0-9]+(\.[0-9]+){0,2}$/', $tag)) {
                unset($tags[$id]);
            }
        }
        $tags = array_values($tags);
        usort($tags, function ($a, $b) {
            return version_compare(ltrim($b, 'v'), ltrim($a, 'v'));
        });
        return $tags;
    }

    private function syncImage(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_TAG, ?string $IMAGE_VERSION = null): void
    {
        if ($IMAGE_TAG === 'null' || $IMAGE_TAG === null) {
            $this->syncImageTag($REGISTRY, $IMAGE_NAME, $IMAGE_VERSION);
            $this->tagImage($IMAGE_NAME, $IMAGE_VERSION, $IMAGE_VERSION);
        } else {
            $this->syncImageTag($REGISTRY, $IMAGE_NAME, $IMAGE_TAG);
            $this->syncImageTag($REGISTRY, $IMAGE_NAME, $IMAGE_VERSION);
        }
    }

    private function syncImageTag(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_TAG): void
    {
        $this->ansiInfo("Syncing image: $REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        $result = $this->skopeo("copy --dest-precompute-digests --preserve-digests --retry-times 10 --override-arch amd64 --override-os linux docker://$REGISTRY/$IMAGE_NAME:$IMAGE_TAG docker://$this->DESTINATION_REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        if ($result->successful()) {
            $this->ansiSuccess("Successfully synced image: $REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        } else {
            $this->ansiError("Failed to sync image: $REGISTRY/$IMAGE_NAME:$IMAGE_TAG");
        }
    }

    private function tagImage(string $REGISTRY, string $IMAGE_NAME, string $IMAGE_VERSION): void
    {
        $this->ansiInfo("Tagging image: $REGISTRY/$IMAGE_NAME:$IMAGE_VERSION");
        $result = $this->skopeo("copy --dest-precompute-digests --preserve-digests --retry-times 10 --override-arch amd64 --override-os linux docker://$REGISTRY/$IMAGE_NAME:$IMAGE_VERSION docker://$this->DESTINATION_REGISTRY/$IMAGE_NAME:latest");
        if ($result->successful()) {
            $this->ansiSuccess("Successfully tagged image: $REGISTRY/$IMAGE_NAME:$IMAGE_VERSION");
        } else {
            $this->ansiError("Failed to tag image: $REGISTRY/$IMAGE_NAME:$IMAGE_VERSION");
        }
    }
}
