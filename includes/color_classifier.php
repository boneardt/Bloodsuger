<?php

/**
 * Bands model: value < A -> red, A <= value < B -> yellow, B <= value <= C -> green,
 * C < value <= D -> yellow, value > D -> red. See Settings for A/B/C/D.
 *
 * Colorblind-safe: never rely on hue alone. Each of the 5 logical states gets its
 * own icon shape and precise text label, not just a color, so the status reads
 * correctly even if red/yellow/green are indistinguishable to the viewer.
 */
function bs_classify_detail(float $value, array $thresholds): array
{
    if ($value < $thresholds['red_low_max']) {
        return ['color' => 'red', 'label' => 'Low', 'code' => 'L', 'icon' => '▼'];
    }
    if ($value < $thresholds['green_min']) {
        return ['color' => 'yellow', 'label' => 'Borderline low', 'code' => 'BL', 'icon' => '▽'];
    }
    if ($value <= $thresholds['green_max']) {
        return ['color' => 'green', 'label' => 'In range', 'code' => 'OK', 'icon' => '✓'];
    }
    if ($value <= $thresholds['red_high_min']) {
        return ['color' => 'yellow', 'label' => 'Borderline high', 'code' => 'BH', 'icon' => '△'];
    }
    return ['color' => 'red', 'label' => 'High', 'code' => 'H', 'icon' => '▲'];
}
