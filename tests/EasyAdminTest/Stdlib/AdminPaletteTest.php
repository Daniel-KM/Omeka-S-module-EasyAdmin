<?php declare(strict_types=1);

namespace EasyAdminTest\Stdlib;

use EasyAdmin\Stdlib\AdminPalette;
use PHPUnit\Framework\TestCase;

class AdminPaletteTest extends TestCase
{
    /**
     * @var \EasyAdmin\Stdlib\AdminPalette
     */
    protected $palette;

    public function setUp(): void
    {
        $this->palette = new AdminPalette();
    }

    /**
     * The color of the admin theme must give back the colors of the admin
     * theme, else a custom color would not keep its design.
     */
    public function testColorOfTheAdminThemeGivesItsShades(): void
    {
        $this->assertSame([
            'base' => '#404e61',
            'shade_5' => '#364252',
            'shade_10' => '#2c3542',
            'shade_15' => '#222933',
            'text' => '#bdcde3',
        ], ($this->palette)('#404E61'));
    }

    public function testShortColorAndColorWithoutSharpAreSupported(): void
    {
        $this->assertSame(($this->palette)('#ffffff'), ($this->palette)('fff'));
        $this->assertSame(($this->palette)('#404e61'), ($this->palette)('404E61'));
    }

    /**
     * @dataProvider providerInvalidColors
     */
    public function testInvalidColorHasNoPalette(?string $color): void
    {
        $this->assertNull(($this->palette)($color));
    }

    public function providerInvalidColors(): array
    {
        return [
            [null],
            [''],
            ['red'],
            ['#12345'],
            ['#1234567'],
            ['#12345g'],
            ['rgb(0, 0, 0)'],
        ];
    }

    /**
     * The texts are light on a dark color and dark on a light one.
     */
    public function testTextIsReadableOnAnyColor(): void
    {
        $dark = ($this->palette)('#8b1a1a');
        $light = ($this->palette)('#bdd8f0');

        $this->assertGreaterThan($this->lightness($dark['base']), $this->lightness($dark['text']));
        $this->assertLessThan($this->lightness($light['base']), $this->lightness($light['text']));
    }

    /**
     * A color too dark to be darkened is lightened, else the shades would be
     * all black.
     */
    public function testShadesOfAVeryDarkColorAreLightened(): void
    {
        $result = ($this->palette)('#111111');

        $this->assertGreaterThan($this->lightness($result['base']), $this->lightness($result['shade_5']));
        $this->assertGreaterThan($this->lightness($result['shade_5']), $this->lightness($result['shade_15']));
    }

    public function testShadesAreDarkerThanTheColor(): void
    {
        $result = ($this->palette)('#8b1a1a');

        $this->assertLessThan($this->lightness($result['base']), $this->lightness($result['shade_5']));
        $this->assertLessThan($this->lightness($result['shade_5']), $this->lightness($result['shade_10']));
        $this->assertLessThan($this->lightness($result['shade_10']), $this->lightness($result['shade_15']));
    }

    /**
     * A gray has no hue, so its texts must stay gray and not be tinted.
     */
    public function testGrayIsNotTinted(): void
    {
        $result = ($this->palette)('#808080');

        foreach ($result as $color) {
            $this->assertSame(
                substr($color, 1, 2) . substr($color, 1, 2),
                substr($color, 3, 2) . substr($color, 5, 2),
                sprintf('The color "%s" is not a gray.', $color)
            );
        }
    }

    protected function lightness(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $max = max(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        $min = min(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        return ($max + $min) / 2;
    }
}
