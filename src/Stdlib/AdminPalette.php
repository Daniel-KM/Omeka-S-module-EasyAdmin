<?php declare(strict_types=1);

namespace EasyAdmin\Stdlib;

/**
 * Build the colors of the admin interface from a single one.
 *
 * The admin theme of Omeka derives its whole palette from one sass color
 * ($blue), with four variations only: three darker shades for the logo, the
 * buttons, the block of the sites and the borders, and one light color for the
 * texts on them. The same variations are rebuilt here, so a custom color keeps
 * the design of the admin interface.
 */
class AdminPalette
{
    /**
     * Shades of the admin theme, as a difference of lightness in percent.
     *
     * @var array
     */
    const SHADES = [
        'shade_5' => 5,
        'shade_10' => 10,
        'shade_15' => 15,
    ];

    /**
     * Below this lightness, a color cannot be darkened visibly any more, so the
     * shades are lightened instead of darkened.
     */
    const MIN_LIGHTNESS = 0.2;

    /**
     * Difference of lightness between a background and its texts.
     */
    const CONTRAST = 0.5;

    /**
     * Get the colors derived from a color, or null when it is not a color.
     *
     * The keys are "base", "shade_5", "shade_10", "shade_15" and "text".
     */
    public function __invoke(?string $color): ?array
    {
        $rgb = $this->hexToRgb((string) $color);
        if ($rgb === null) {
            return null;
        }

        [$hue, $lightness, $saturation] = $this->rgbToHsl($rgb);

        // A dark color cannot be darkened, so the shades are lightened.
        $way = $lightness < self::MIN_LIGHTNESS ? 1 : -1;

        $result = ['base' => $this->rgbToHex($rgb)];
        foreach (self::SHADES as $name => $difference) {
            $result[$name] = $this->hslToHex($hue, $lightness + $way * $difference / 100, $saturation);
        }

        // The texts are light on a dark color and dark on a light one, so they
        // are readable whatever the color. A gray has no hue, so it is kept
        // gray instead of being tinted by the saturation.
        $textSaturation = $saturation > 0 ? $saturation + 0.2 : 0.0;
        $result['text'] = $lightness < 0.5
            ? $this->hslToHex($hue, $lightness + self::CONTRAST, $textSaturation)
            : $this->hslToHex($hue, $lightness - self::CONTRAST, $textSaturation);

        return $result;
    }

    protected function hexToRgb(string $color): ?array
    {
        $color = ltrim(trim($color), '#');
        if (!preg_match('~^[0-9a-fA-F]{3}$|^[0-9a-fA-F]{6}$~', $color)) {
            return null;
        }
        if (strlen($color) === 3) {
            $color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
        }
        return [
            hexdec(substr($color, 0, 2)) / 255,
            hexdec(substr($color, 2, 2)) / 255,
            hexdec(substr($color, 4, 2)) / 255,
        ];
    }

    protected function rgbToHex(array $rgb): string
    {
        $hex = '#';
        foreach ($rgb as $value) {
            $hex .= str_pad(dechex((int) round(max(0.0, min(1.0, $value)) * 255)), 2, '0', STR_PAD_LEFT);
        }
        return $hex;
    }

    protected function hslToHex(float $hue, float $lightness, float $saturation): string
    {
        return $this->rgbToHex($this->hslToRgb(
            $hue,
            max(0.0, min(1.0, $lightness)),
            max(0.0, min(1.0, $saturation))
        ));
    }

    /**
     * @return array Hue, lightness and saturation, between 0 and 1.
     */
    protected function rgbToHsl(array $rgb): array
    {
        [$red, $green, $blue] = $rgb;
        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;
        if ($delta == 0.0) {
            return [0.0, $lightness, 0.0];
        }
        $saturation = $lightness > 0.5
            ? $delta / (2 - $max - $min)
            : $delta / ($max + $min);
        if ($max == $red) {
            $hue = (($green - $blue) / $delta) + ($green < $blue ? 6 : 0);
        } elseif ($max == $green) {
            $hue = (($blue - $red) / $delta) + 2;
        } else {
            $hue = (($red - $green) / $delta) + 4;
        }
        return [$hue / 6, $lightness, $saturation];
    }

    protected function hslToRgb(float $hue, float $lightness, float $saturation): array
    {
        if ($saturation == 0.0) {
            return [$lightness, $lightness, $lightness];
        }
        $second = $lightness < 0.5
            ? $lightness * (1 + $saturation)
            : $lightness + $saturation - $lightness * $saturation;
        $first = 2 * $lightness - $second;
        return [
            $this->hueToRgb($first, $second, $hue + 1 / 3),
            $this->hueToRgb($first, $second, $hue),
            $this->hueToRgb($first, $second, $hue - 1 / 3),
        ];
    }

    protected function hueToRgb(float $first, float $second, float $hue): float
    {
        if ($hue < 0) {
            ++$hue;
        } elseif ($hue > 1) {
            --$hue;
        }
        if ($hue < 1 / 6) {
            return $first + ($second - $first) * 6 * $hue;
        }
        if ($hue < 1 / 2) {
            return $second;
        }
        if ($hue < 2 / 3) {
            return $first + ($second - $first) * (2 / 3 - $hue) * 6;
        }
        return $first;
    }
}
