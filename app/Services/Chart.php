<?php

namespace App\Services;

class Chart
{
    public static function bars(iterable $rows, string $value, string $label): string
    {
        $items = collect($rows)->values();
        if ($items->isEmpty()) {
            return '<p>No analyzed values yet.</p>';
        }
        $max = max(1, (float) $items->max($value));
        $width = max(500, $items->count() * 55);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Trend chart. Exact values in the table below." viewBox="0 0 '.$width.' 190">';
        foreach ($items as $i => $row) {
            $height = 120 * (float) $row->$value / $max;
            $x = 15 + $i * ($width - 20) / $items->count();
            $text = htmlspecialchars(substr((string) $row->$label, 0, 10), ENT_QUOTES, 'UTF-8');
            $svg .= '<rect x="'.$x.'" y="'.(140 - $height).'" width="23" height="'.$height.'" rx="3" fill="#34724e"/><text x="'.$x.'" y="16" font-size="10">'.(float) $row->$value.'</text><text x="'.$x.'" y="162" font-size="9">'.$text.'</text>';
        }

        return $svg.'</svg>';
    }
}
