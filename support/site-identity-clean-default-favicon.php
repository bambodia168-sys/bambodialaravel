<?php

/**
 * Bambodia default browser favicon white-corner repair.
 *
 * php support/site-identity-clean-default-favicon.php --check [app-root]
 * php support/site-identity-clean-default-favicon.php --apply [app-root]
 *
 * Preserves public/favicon.png and the existing brand artwork. Generates a
 * new public/favicon-clean.png with only connected, near-white outer matte
 * pixels removed. The SiteBrandAsset model uses it only when present.
 *
 * Review --check output and the source artwork before applying in production.
 */
declare(strict_types=1);

$mode = $argv[1] ?? '';
if (! in_array($mode, ['--check', '--apply'], true) || count($argv) > 3) {
    fwrite(STDERR, "Usage: php site-identity-clean-default-favicon.php --check|--apply [app-root]\n");
    exit(2);
}

$root = realpath($argv[2] ?? dirname(__DIR__));
$fail = static function (string $reason): never {
    fwrite(STDERR, "BLOCKED={$reason}\n");
    exit(2);
};

if ($root === false || ! is_file($root.'/artisan') || ! is_dir($root.'/public')) {
    $fail('Invalid Bambodia application root.');
}

$source = $root.'/public/favicon.png';
$target = $root.'/public/favicon-clean.png';

if (! is_file($source) || is_link($source) || is_link($target)) {
    $fail('Missing or linked favicon source/target.');
}
if (! function_exists('imagecreatefrompng') || ! function_exists('imagepng')) {
    $fail('PHP GD extension is required to preserve the source image artwork and alpha.');
}

$bytes = filesize($source);
$imageInfo = @getimagesize($source);
if ($bytes === false || $bytes < 50 || $bytes > 3 * 1024 * 1024
    || ! is_array($imageInfo) || ($imageInfo['mime'] ?? null) !== 'image/png') {
    $fail('Source must be a valid PNG no larger than 3 MB.');
}

$width = (int) $imageInfo[0];
$height = (int) $imageInfo[1];
if ($width !== $height || $width < 32 || $width > 1024) {
    $fail('Favicon source must be square, between 32 and 1024 pixels.');
}

$image = @imagecreatefrompng($source);
if ($image === false) {
    $fail('Unable to load PNG with GD.');
}
if (! imageistruecolor($image) && ! imagepalettetotruecolor($image)) {
    $fail('Unable to convert the source to RGBA.');
}
imagealphablending($image, false);
imagesavealpha($image, true);

$isOuterWhite = static function (int $x, int $y) use ($image): bool {
    $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
    $red = (int) $c['red'];
    $green = (int) $c['green'];
    $blue = (int) $c['blue'];

    // Neutral white connected to the canvas border, not the blue/yellow logo.
    return $c['alpha'] < 110
        && min($red, $green, $blue) >= 231
        && max($red, $green, $blue) - min($red, $green, $blue) <= 30;
};

$area = $width * $height;
$seen = new SplFixedArray($area);
$queue = new SplQueue();

$enqueue = static function (int $x, int $y) use ($width, $height, $seen, $queue): void {
    if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
        return;
    }

    $index = $y * $width + $x;
    if ($seen[$index]) {
        return;
    }

    $seen[$index] = true;
    $queue->enqueue([$x, $y]);
};

for ($x = 0; $x < $width; $x++) {
    $enqueue($x, 0);
    $enqueue($x, $height - 1);
}
for ($y = 0; $y < $height; $y++) {
    $enqueue(0, $y);
    $enqueue($width - 1, $y);
}

$removed = 0;
$transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);

while (! $queue->isEmpty()) {
    [$x, $y] = $queue->dequeue();

    if (! $isOuterWhite($x, $y)) {
        continue;
    }

    imagesetpixel($image, $x, $y, $transparent);
    $removed++;

    $enqueue($x - 1, $y);
    $enqueue($x + 1, $y);
    $enqueue($x, $y - 1);
    $enqueue($x, $y + 1);
}

echo "SOURCE_FILE=favicon.png\n";
echo "SOURCE_SHA256=".hash_file('sha256', $source)."\n";
echo "SOURCE_DIMENSIONS={$width}x{$height}\n";
echo "CONNECTED_OUTER_WHITE_PIXELS={$removed}\n";
echo "ORIGINAL_ARTWORK=UNMODIFIED\n";

if ($removed < 4) {
    $fail('No connected white outer matte found. Keep the current asset and review it manually.');
}

if ($mode === '--check') {
    echo "NEW_FILE=NOT_CREATED\n";
    echo "RESULT=READY_TO_CREATE_TRANSPARENT_DEFAULT_FAVICON\n";
    exit(0);
}

if (file_exists($target)) {
    $fail('favicon-clean.png already exists. Do not overwrite it automatically.');
}

$tmp = tempnam($root.'/public', '.favicon-clean-');
if ($tmp === false) {
    $fail('Cannot create temporary icon in public directory.');
}

try {
    if (! imagepng($image, $tmp, 9)) {
        $fail('Failed to encode the corrected transparent PNG.');
    }

    $createdInfo = @getimagesize($tmp);
    if (! is_array($createdInfo) || $createdInfo[0] !== $width
        || $createdInfo[1] !== $height || ($createdInfo['mime'] ?? '') !== 'image/png') {
        $fail('Generated icon failed dimension or PNG validation.');
    }

    chmod($tmp, 0644);
    if (! rename($tmp, $target)) {
        $fail('Unable to atomically publish corrected favicon.');
    }
    $tmp = '';
} finally {
    if ($tmp !== '' && is_file($tmp)) {
        unlink($tmp);
    }
}

echo "NEW_FILE=favicon-clean.png\n";
echo "NEW_SHA256=".hash_file('sha256', $target)."\n";
echo "RESULT=TRANSPARENT_DEFAULT_FAVICON_CREATED_ORIGINAL_PRESERVED\n";
