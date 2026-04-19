<?php

declare(strict_types=1);

namespace App\Service;

class MeetingColorService
{
    /**
     * Color palette for supplier meetings - agricultural theme
     */
    private const COLORS = [
        '#116530', // Primary green
        '#2ea359', // Accent green
        '#43682b', // Darker green
        '#8B4513', // Brown
        '#D2691E', // Chocolate
        '#CD5C5C', // Indian red
        '#4169E1', // Royal blue
        '#20B2AA', // Light sea green
        '#6A5ACD', // Slate blue
        '#FF8C00', // Dark orange
    ];

    /**
     * Get a consistent color for a supplier based on their ID
     *
     * @param int $supplierId
     * @return string Hex color code
     */
    public function getColorForSupplier(int $supplierId): string
    {
        $index = $supplierId % count(self::COLORS);
        return self::COLORS[$index];
    }

    /**
     * Get all available colors
     *
     * @return array<string>
     */
    public function getAllColors(): array
    {
        return self::COLORS;
    }

    /**
     * Get a CSS variable name for a supplier color
     *
     * @param int $supplierId
     * @return string CSS variable name (--supplier-color-X)
     */
    public function getColorVariableName(int $supplierId): string
    {
        $index = $supplierId % count(self::COLORS);
        return '--supplier-color-' . $index;
    }
}
