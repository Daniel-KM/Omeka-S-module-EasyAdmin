#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Thumbnail generation script using VIPS or ImageMagick.
 *
 * @copyright Daniel Berthereau 2025
 * @license Cecill 2.1
 *
 * Copied:
 * @see modules/EasyAdmin/data/scripts/thumbnailize.php
 * @see modules/Vips/data/scripts/thumbnailize.php
 */
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

/***************************************************
 * DEFAULT CONFIG
 ***************************************************/
$MAIN_DIR = 'files';
$ORIGINAL_DIR = "$MAIN_DIR/original";
$LARGE_DIR = "$MAIN_DIR/large";
$MEDIUM_DIR = "$MAIN_DIR/medium";
$SQUARE_DIR = "$MAIN_DIR/square";

$LARGE_SIZE = 800;
$MEDIUM_SIZE = 200;
$SQUARE_SIZE = 200;

$LOG_FILE = 'thumbnailize.log';
$MODE = 'missing';
$DRYRUN = false;
$PARALLEL = 1;
$PROGRESS = true;
$PDF_DPI = 150;
$CROP_MODE = 'centre';

/***************************************************
 * PARSE CLI OPTIONS
 ***************************************************/
$options = getopt('', [
    'all', 'missing', 'parallel:', 'dry-run', 'log-file:', 'no-progress',
    'pdf-dpi:', 'crop-mode:', 'main-dir:', 'help',
]);

if (isset($options['help'])) {
    echo "Usage: php thumbnailize.php [OPTIONS]\n";
    echo "--all                Process all files\n";
    echo "--missing            Process only missing\n";
    echo "--parallel N         Number of parallel workers\n";
    echo "--dry-run            No conversions, just print actions\n";
    echo "--log-file FILE      Set log file (default thumbnalize.log)\n";
    echo "--no-progress        Disable progress bar\n";
    echo "--pdf-dpi N          DPI used for PDF rendering\n";
    echo "--crop-mode MODE     centre|entropy|attention|face|document\n";
    echo "--main-dir DIR       Override base directory\n";
    exit(0);
}

if (isset($options['all'])) {
    $MODE = 'all';
}
if (isset($options['missing'])) {
    $MODE = 'missing';
}
if (isset($options['parallel'])) {
    $PARALLEL = max(1, (int) $options['parallel']);
}
if (isset($options['dry-run'])) {
    $DRYRUN = true;
}
if (isset($options['log-file'])) {
    $LOG_FILE = $options['log-file'];
}
if (isset($options['no-progress'])) {
    $PROGRESS = false;
}
if (isset($options['pdf-dpi'])) {
    $PDF_DPI = (int) $options['pdf-dpi'];
}
if (isset($options['crop-mode'])) {
    $CROP_MODE = $options['crop-mode'];
}
if (isset($options['main-dir'])) {
    $MAIN_DIR = rtrim($options['main-dir'], '/');
    $ORIGINAL_DIR = "$MAIN_DIR/original";
    $LARGE_DIR = "$MAIN_DIR/large";
    $MEDIUM_DIR = "$MAIN_DIR/medium";
    $SQUARE_DIR = "$MAIN_DIR/square";
}

/***************************************************
 * CHECK DEPENDENCIES
 ***************************************************/
$USE_VIPS = true;

$dummy = $ret = null;
exec('command -v vips', $dummy, $ret);
if ($ret !== 0) {
    echo "Warning: VIPS not found, using ImageMagick convert instead.\n";
    $USE_VIPS = false;
}

if (!$USE_VIPS) {
    exec('command -v convert', $dummy, $ret);
    if ($ret !== 0) {
        die("Error: Neither VIPS nor ImageMagick convert is installed.\n");
    }
}

if ($PARALLEL > 1) {
    if (!function_exists('pcntl_fork')) {
        die("pcntl extension is required for parallel processing.\n");
    }
}

/***************************************************
 * SETUP
 ***************************************************/
@mkdir($LARGE_DIR, 0777, true);
@mkdir($MEDIUM_DIR, 0777, true);
@mkdir($SQUARE_DIR, 0777, true);
touch($LOG_FILE);

$files = glob("$ORIGINAL_DIR/*.{jpg,jpeg,png,webp,tif,tiff,pdf,JPG,JPEG,PNG,WEBP,TIF,TIFF,PDF}", GLOB_BRACE);
$total = count($files);

/***************************************************
 * HELPERS
 ***************************************************/
function logMsg(string $msg, string $file): void
{
    file_put_contents($file, $msg . "\n", FILE_APPEND);
}

function progressBar(int $count, int $total): void
{
    $width = 40;
    $percent = intval($count * 100 / max(1, $total));
    $filled = intval($width * $count / max(1, $total));
    $empty = $width - $filled;

    printf("\r[%s%s] %d%% (%d/%d)",
        str_repeat('#', $filled),
        str_repeat('-', $empty),
        $percent, $count, $total
    );
}

/***************************************************
 * TYPE DETECTION
 ***************************************************/
function detectType(string $file, bool $useVips): string
{
    if ($useVips) {
        $o = $ret = null;
        exec('vipsheader -f format ' . escapeshellarg($file), $o, $ret);
        if ($ret === 0 && !empty($o)) {
            return trim($o[0]);
        }
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return 'pdfload';
    }
    return 'image';
}

/***************************************************
 * RUN EXECS WITH FALLBACK
 ***************************************************/
function runVipsOrConvert(string $vipsCmd, string $convertCmd, bool $useVips): void
{
    if ($useVips) {
        $o = $ret = null;
        exec($vipsCmd, $o, $ret);
        if ($ret === 0) {
            return;
        }
    }
    exec($convertCmd);
}

/***************************************************
 * PROCESS ONE FILE
 ***************************************************/
function processFile(string $img, array $cfg): void
{
    $base = basename($img);
    $filetype = detectType($img, $cfg['use_vips']);

    if ($filetype === 'unknown') {
        logMsg("[skip] Unknown: $base", $cfg['log_file']);
        return;
    }

    $filename = pathinfo($base, PATHINFO_FILENAME) . '.jpg';
    $large_out = "{$cfg['large_dir']}/$filename";
    $medium_out = "{$cfg['medium_dir']}/$filename";
    $squareOut = "{$cfg['square_dir']}/$filename";

    if ($cfg['mode'] === 'missing'
        && file_exists($large_out)
        && file_exists($medium_out)
        && file_exists($squareOut)) {
        logMsg("[skip] $base", $cfg['log_file']);
        return;
    }

    logMsg("[process] $base ($filetype)", $cfg['log_file']);

    $thumbSrc = $img;
    $tempPDF = null;

    /********************
     * PDF HANDLING
     ********************/
    if ($filetype === 'pdfload') {
        $tempPDF = tempnam(sys_get_temp_dir(), 'pdf') . '.jpg';

        if (!$cfg['dryrun']) {
            $vipsPDF = sprintf(
                "vips pdfload %s %s --page=0 --dpi=%d --n=1 --access=sequential --flatten --background '255 255 255'",
                escapeshellarg($img),
                escapeshellarg($tempPDF),
                $cfg['pdf_dpi']
            );

            $convertPDF = sprintf(
                'convert -density %d %s[0] -background white -flatten %s',
                $cfg['pdf_dpi'],
                escapeshellarg($img),
                escapeshellarg($tempPDF)
            );

            runVipsOrConvert($vipsPDF, $convertPDF, $cfg['use_vips']);
        }

        $thumbSrc = $tempPDF;
    }

    if (!$cfg['dryrun']) {

        /********************
         * LARGE
         ********************/
        $thumbSize = $cfg['large_size'];

        runVipsOrConvert(
            'vips thumbnail '
                . escapeshellarg($thumbSrc)
                . ' ' . escapeshellarg($large_out)
                . " $thumbSize --size=down",

            'convert '
                . escapeshellarg($thumbSrc)
                . " -resize {$thumbSize}x{$thumbSize}> "
                . escapeshellarg($large_out),

            $cfg['use_vips']
        );

        /********************
         * MEDIUM
         ********************/
        $thumbSize = $cfg['medium_size'];

        runVipsOrConvert(
            'vips thumbnail '
                . escapeshellarg($thumbSrc)
                . ' ' . escapeshellarg($medium_out)
                . " $thumbSize --size=down",

            'convert '
                . escapeshellarg($thumbSrc)
                . " -resize {$thumbSize}x{$thumbSize}> "
                . escapeshellarg($medium_out),

            $cfg['use_vips']
        );

        /********************
         * SQUARE
         ********************/
        $thumbSize = $cfg['square_size'];

        // --- VIPS ---
        $vipsCmd =
        'vips thumbnail '
            . escapeshellarg($thumbSrc)
            . ' '
            . escapeshellarg($squareOut)
            . ' ' . $thumbSize
            . ' --height ' . $thumbSize
            . ' --size both'
            . " --crop {$cfg['crop_mode']}";

        // --- Convert fallback ---
        // Convert has no entropy/attention modes like VIPS,
        // but it can be emulated using -gravity center.
        $convertCmd =
        'convert '
            . escapeshellarg($thumbSrc)
            . " -resize {$thumbSize}x{$thumbSize}^"
            . ' -gravity center'
            . " -extent {$thumbSize}x{$thumbSize} "
            . escapeshellarg($squareOut);

        runVipsOrConvert($vipsCmd, $convertCmd, $cfg['use_vips']);
    }

    if ($tempPDF && !$cfg['dryrun']) {
        @unlink($tempPDF);
    }
}

/***************************************************
 * CONFIG STRUCT FOR PASSING
 ***************************************************/
$config = [
    'mode' => $MODE,
    'dryrun' => $DRYRUN,
    'log_file' => $LOG_FILE,
    'large_dir' => $LARGE_DIR,
    'medium_dir' => $MEDIUM_DIR,
    'square_dir' => $SQUARE_DIR,
    'large_size' => $LARGE_SIZE,
    'medium_size' => $MEDIUM_SIZE,
    'square_size' => $SQUARE_SIZE,
    'pdf_dpi' => $PDF_DPI,
    'crop_mode' => $CROP_MODE,
    'use_vips' => $USE_VIPS,
];

/***************************************************
 * RUN
 ***************************************************/
echo "Mode: $MODE\nParallel: $PARALLEL\nUsing VIPS: " . ($USE_VIPS ? 'yes' : 'no') . "\nFound $total files\nStarting...\n";

if ($PARALLEL > 1) {
    /**************
     * PARALLEL
     **************/

    $pool = [];
    $finished = 0;
    $status = null;

    foreach ($files as $img) {

        while (count($pool) >= $PARALLEL) {
            foreach ($pool as $key => $pid) {
                $done = pcntl_waitpid($pid, $status, WNOHANG);
                if ($done > 0) {
                    unset($pool[$key]);
                    $finished++;
                    if ($PROGRESS) {
                        progressBar($finished, $total);
                    }
                }
            }
            usleep(50000);
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            die("Failed to fork.\n");
        }
        if ($pid === 0) {
            processFile($img, $config);
            exit(0);
        }
        $pool[] = $pid;
    }

    while (!empty($pool)) {
        foreach ($pool as $key => $pid) {
            $done = pcntl_waitpid($pid, $status, WNOHANG);
            if ($done > 0) {
                unset($pool[$key]);
                $finished++;
                if ($PROGRESS) {
                    progressBar($finished, $total);
                }
            }
        }
        usleep(50000);
    }

} else {
    /**************
     * SERIAL
     **************/

    $count = 0;
    foreach ($files as $img) {
        processFile($img, $config);
        $count++;
        if ($PROGRESS) {
            progressBar($count, $total);
        }
    }
}

echo "\nDONE.\n";
