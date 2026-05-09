<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace filter_embeddiscussion;

/**
 * GitHub-style identicon avatar generator.
 *
 * Produces a deterministic 5×5 symmetric coloured grid from a seed string,
 * encoded as a base64 PNG data URI suitable for use in an img src.
 * Uses PHP's GD extension, which is a hard Moodle dependency.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class identicon {
    /**
     * Generate a base64 PNG data URI for the given seed.
     *
     * Memoised in the 'identicons' application cache: identicons are a pure function
     * of (seed, size) so the GD work runs once and subsequent requests reuse the
     * encoded data URI until the cache is purged.
     *
     * @param string $seed
     * @param int $size pixel size of the square avatar
     * @return string data: URI suitable for an img src, or '' if GD is unavailable
     */
    public static function data_uri(string $seed, int $size = 100): string {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return '';
        }

        $cache = \cache::make('filter_embeddiscussion', 'identicons');
        $key = sha1($seed . ':' . $size);
        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }

        $uri = self::generate($seed, $size);
        $cache->set($key, $uri);
        return $uri;
    }

    /**
     * Render the identicon for a seed and encode it as a base64 PNG data URI.
     *
     * @param string $seed
     * @param int $size
     * @return string
     */
    private static function generate(string $seed, int $size): string {
        // Derive colour and grid from a SHA-256 hash of the seed.
        $hash = hash('sha256', $seed);

        // Foreground hue from the first byte (0–255 → 0–360°), fixed saturation/lightness.
        $hue = hexdec(substr($hash, 0, 2)) / 255 * 360;
        [$r, $g, $b] = self::hsl_to_rgb($hue, 0.65, 0.45);

        // 5×5 symmetric grid: only the left 3 columns are seeded; cols 3–4 mirror cols 1–0.
        $filled = [];
        for ($row = 0; $row < 5; $row++) {
            for ($col = 0; $col < 3; $col++) {
                $byte = hexdec(substr($hash, 2 + ($row * 3 + $col) * 2, 2));
                $filled[$row][$col] = ($byte % 2 === 0);
            }
            $filled[$row][3] = $filled[$row][1];
            $filled[$row][4] = $filled[$row][0];
        }

        $cellsize = $size / 5;
        $padding = max(1, (int) round($size / 50));
        $im = imagecreatetruecolor($size, $size);
        $bg = imagecolorallocate($im, 240, 240, 240);
        $fg = imagecolorallocate($im, $r, $g, $b);
        imagefill($im, 0, 0, $bg);

        for ($row = 0; $row < 5; $row++) {
            for ($col = 0; $col < 5; $col++) {
                if ($filled[$row][$col]) {
                    $x1 = (int) ($col * $cellsize) + $padding;
                    $y1 = (int) ($row * $cellsize) + $padding;
                    $x2 = (int) (($col + 1) * $cellsize) - $padding - 1;
                    $y2 = (int) (($row + 1) * $cellsize) - $padding - 1;
                    imagefilledrectangle($im, $x1, $y1, $x2, $y2, $fg);
                }
            }
        }

        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * Convert HSL colour values to an RGB triple.
     *
     * @param float $h Hue in degrees [0, 360).
     * @param float $s Saturation [0, 1].
     * @param float $l Lightness [0, 1].
     * @return array{int, int, int} [red, green, blue] each in [0, 255].
     */
    private static function hsl_to_rgb(float $h, float $s, float $l): array {
        $h /= 360;
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $huetorgb = static function (float $p, float $q, float $t): float {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            if ($t < 1 / 6) {
                return $p + ($q - $p) * 6 * $t;
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + ($q - $p) * (2 / 3 - $t) * 6;
            }
            return $p;
        };
        return [
            (int) round($huetorgb($p, $q, $h + 1 / 3) * 255),
            (int) round($huetorgb($p, $q, $h) * 255),
            (int) round($huetorgb($p, $q, $h - 1 / 3) * 255),
        ];
    }
}
