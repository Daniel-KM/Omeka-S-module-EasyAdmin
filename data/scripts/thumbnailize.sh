#!/usr/bin/env bash
[ -z "$BASH_VERSION" ] && exec bash "$0" "$@"

############################################
# Thumbnail generation script using VIPS or ImageMagick.
#
# Copyright Daniel Berthereau 2025
# Licence Cecill 2.1
#
# Copied:
# @see modules/EasyAdmin/data/scripts/thumbnailize.sh
# @see modules/Vips/data/scripts/thumbnailize.sh
############################################

set -euo pipefail

############################################
# DEFAULT CONFIGURATION
############################################
MAIN_DIR="files"
ORIGINAL_DIR="$MAIN_DIR/original"
LARGE_DIR="$MAIN_DIR/large"
MEDIUM_DIR="$MAIN_DIR/medium"
SQUARE_DIR="$MAIN_DIR/square"

LARGE_SIZE=800
MEDIUM_SIZE=200
SQUARE_SIZE=200

LOG_FILE="thumbnailize.log"
MODE="missing"
DRYRUN=false
PARALLEL=1
PROGRESS=true
PDF_DPI=150
CROP_MODE="centre"

TMPCOUNT=$(mktemp /tmp/thumb_count_XXXXXX.tmp)
touch "$TMPCOUNT"

############################################
# DEPENDENCY CHECKS
############################################
USE_VIPS=true

if ! command -v vips &>/dev/null; then
    echo "Warning: VIPS is not installed. Falling back to ImageMagick convert."
    USE_VIPS=false
fi

if ! $USE_VIPS; then
    if ! command -v convert &>/dev/null; then
        echo "Error: Neither vips nor convert found. Install at least one."
        exit 1
    fi
fi

# Only check GNU parallel if using parallel > 1.
if [[ "$PARALLEL" -gt 1 ]] && ! command -v parallel &>/dev/null; then
    echo "Error: GNU parallel not found but --parallel was used."
    exit 1
fi

############################################
# HELP
############################################
usage() {
    cat <<EOF
Usage: $0 [OPTIONS]

Options:
  --all                Process all files (overwrite existing)
  --missing            Process only missing thumbnails (default)
  --parallel N         Run N parallel jobs
  --dry-run            Show actions but do not run converters
  --log-file FILE      Set log file (default: thumbnailize.log)
  --no-progress        Disable progress bar
  --pdf-dpi N          Set dpi for pdf rendering
  --crop-mode MODE     Smart crop mode: centre (default), face, entropy, attention, document
  --main-dir DIR       Main directory (default: files/)
  --help               Show help

Examples:

# Process all files in the original directory, creating thumbnails:
$0 --all

# Process only missing thumbnails (default behavior):
$0

# Use 4 parallel jobs for faster processing:
$0 --parallel 4

# Dry-run to see what would be done without writing files:
$0 --dry-run

# Specify pdf dpi and log file:
$0 --pdf-dpi 200 --log-file mylog.log

# Use smart face-aware cropping for square thumbnails:
$0 --crop-mode face

# Combine options: parallel + all + dry-run + entropy crop:
$0 --all --parallel 8 --dry-run --crop-mode entropy
EOF
}

############################################
# PARSE ARGUMENTS
############################################
while [[ $# -gt 0 ]]; do
    case "$1" in
        --all) MODE="all" ;;
        --missing) MODE="missing" ;;
        --parallel) PARALLEL="$2"; shift ;;
        --dry-run) DRYRUN=true ;;
        --log-file) LOG_FILE="$2"; shift ;;
        --no-progress) PROGRESS=false ;;
        --pdf-dpi) PDF_DPI="$2"; shift ;;
        --crop-mode) CROP_MODE="$2"; shift ;;
        --main-dir) MAIN_DIR="$2"; shift ;;
        --help) usage; exit 0 ;;
        *) echo "Unknown option: $1"; usage; exit 1 ;;
    esac
    shift
done

# Update directories based on MAIN_DIR.
ORIGINAL_DIR="$MAIN_DIR/original"
LARGE_DIR="$MAIN_DIR/large"
MEDIUM_DIR="$MAIN_DIR/medium"
SQUARE_DIR="$MAIN_DIR/square"

############################################
# PREP
############################################
mkdir -p "$LARGE_DIR" "$MEDIUM_DIR" "$SQUARE_DIR"
touch "$LOG_FILE"
shopt -s nullglob

file_list=$(mktemp /tmp/thumb_files_XXXXXX.txt)
find "$ORIGINAL_DIR" -maxdepth 1 -type f \
    \( -iname "*.jpg" -o -iname "*.jpeg" \
    -o -iname "*.png" \
    -o -iname "*.webp" \
    -o -iname "*.tif" -o -iname "*.tiff" \
    -o -iname "*.pdf" \) \
    > "$file_list"

TOTAL=$(wc -l < "$file_list")
COUNT=0

############################################
# TYPE DETECTION
############################################
detect_type() {
    local file="$1"
    if $USE_VIPS && vipsheader -f format "$file" &>/dev/null; then
        echo "$(vipsheader -f format "$file")"
        return
    fi
    [[ "$file" =~ \.pdf$|\.PDF$ ]] && echo "pdfload" && return
    echo "image"
}

############################################
# PROGRESS BAR
############################################
progress_bar() {
    [[ "$PROGRESS" == false ]] && return
    local width=40
    local percent=$((100 * COUNT / (TOTAL == 0 ? 1 : TOTAL)))
    local filled=$((width * COUNT / (TOTAL == 0 ? 1 : TOTAL)))
    local empty=$((width - filled))

    printf "\r["
    printf "%0.s#" $(seq 1 $filled)
    printf "%0.s-" $(seq 1 $empty)
    printf "] %d%% (%d/%d)" "$percent" "$COUNT" "$TOTAL"
}

############################################
# CONVERSION HELPERS (VIPS + fallback convert)
############################################
convert_large() {
    local in="$1" out="$2"

    if $USE_VIPS; then
        if vips thumbnail "$in" "$out" $LARGE_SIZE --size=down 2>/dev/null; then
            return 0
        fi
    fi

    convert "$in" -resize "${LARGE_SIZE}x${LARGE_SIZE}>" "$out"
}

convert_medium() {
    local in="$1" out="$2"

    if $USE_VIPS; then
        if vips thumbnail "$in" "$out" $MEDIUM_SIZE --size=down 2>/dev/null; then
            return 0
        fi
    fi

    convert "$in" -resize "${MEDIUM_SIZE}x${MEDIUM_SIZE}>" "$out"
}

convert_square() {
    local in="$1" out="$2"

    # VIPS version
    if $USE_VIPS; then

        if vips thumbnail "$in" "$out" "${SQUARE_SIZE}" --height "${SQUARE_SIZE}" \
            --size both --crop "$CROP_MODE" 2>/dev/null; then
            return 0
        fi
    fi

    # ImageMagick fallback (resize first, then crop)
    convert "$in" \
        -resize "${SQUARE_SIZE}x${SQUARE_SIZE}^" \
        -gravity center \
        -extent "${SQUARE_SIZE}x${SQUARE_SIZE}" \
        "$out"
}

############################################
# PROCESS SINGLE FILE
############################################
process_file() {
    local img="$1"
    local base=$(basename "$img")
    local filetype=$(detect_type "$img")

    if [[ "$filetype" == "unknown" ]]; then
        echo "[skip]   Unknown: $base" >> "$LOG_FILE"
        return
    fi

    # Replace extension with .jpg.
    local filename="${base%.*}.jpg"
    local large_out="$LARGE_DIR/$filename"
    local medium_out="$MEDIUM_DIR/$filename"
    local square_out="$SQUARE_DIR/$filename"

    if [[ "$MODE" == "missing" ]]; then
        if [[ -f "$large_out" && -f "$medium_out" && -f "$square_out" ]]; then
            echo "[skip]   $base" >> "$LOG_FILE"
            return
        fi
    fi

    echo "[process] $base ($filetype)" >> "$LOG_FILE"

    local thumbnail_input="$img"
    local temp_flattened=""
    local is_pdf=false

    # PDF handling.
    if [[ "$filetype" == "pdfload" ]]; then
        is_pdf=true
        temp_flattened=$(mktemp /tmp/pdf_flat_XXXXXX.jpg)

        if [[ "$DRYRUN" == false ]]; then
            if $USE_VIPS; then
                if ! vips pdfload "$img" "$temp_flattened" \
                    --page=0 \
                    --dpi=$PDF_DPI \
                    --n=1 \
                    --access=sequential \
                    --flatten \
                    --background "255 255 255" \
                    2>/dev/null; then
                    convert -density "$PDF_DPI" "$img[0]" \
                        -background white -flatten "$temp_flattened"
                fi
            else
                convert -density "$PDF_DPI" "$img[0]" \
                    -background white \
                    -flatten "$temp_flattened"
            fi
        fi

        thumbnail_input="$temp_flattened"
    fi

    # Create outputs.
    if [[ "$DRYRUN" == false ]]; then
        convert_large  "$thumbnail_input" "$large_out"
        convert_medium "$thumbnail_input" "$medium_out"
        convert_square "$thumbnail_input" "$square_out"
    fi

    [[ "$is_pdf" == true && "$DRYRUN" == false ]] && rm -f "$temp_flattened"

    echo 1 >> "$TMPCOUNT"
}

############################################
# EXPORT FOR PARALLEL
############################################
export -f process_file detect_type progress_bar \
       convert_large convert_medium convert_square
export MODE DRYRUN LOG_FILE LARGE_DIR MEDIUM_DIR SQUARE_DIR \
       LARGE_SIZE MEDIUM_SIZE SQUARE_SIZE PDF_DPI CROP_MODE TMPCOUNT USE_VIPS

############################################
# EXECUTION
############################################
echo "Mode: $MODE"
echo "Parallel jobs: $PARALLEL"
echo "Dry-run: $DRYRUN"
echo "Progress bar: $PROGRESS"
echo "PDF DPI: $PDF_DPI"
echo "Crop mode: $CROP_MODE"
echo "Log-file: $LOG_FILE"
echo "Main directory: $MAIN_DIR"
echo "Using VIPS: $USE_VIPS"
echo "Total files found: $TOTAL"
echo

run_serial() {
    while read -r img; do
        process_file "$img"
        COUNT=$(wc -l < "$TMPCOUNT")
        progress_bar
    done < "$file_list"
}

############################################
# RUN PARALLEL
############################################
run_parallel() {
    (
      while true; do
        COUNT=$(wc -l < "$TMPCOUNT")
        progress_bar
        sleep 0.2
        [[ $COUNT -ge $TOTAL ]] && break
      done
    ) &
    PROGRESS_PID=$!

    # Correct GNU parallel invocation.
    parallel -j "$PARALLEL" --arg-file "$file_list" process_file

    wait
    kill $PROGRESS_PID 2>/dev/null || true
}

############################################
# EXECUTION
############################################
echo "Starting…"
echo "Logging to $LOG_FILE"
echo

if [[ "$PARALLEL" -gt 1 ]]; then
    run_parallel
else
    run_serial
fi

echo
echo "DONE."

# Cleanup.
rm -f "$TMPCOUNT" "$file_list"
