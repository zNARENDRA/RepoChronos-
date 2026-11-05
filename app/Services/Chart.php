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
        $width = max(500, $items->count() * 110);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Trend chart. Exact values in the table below." style="min-width:'.$width.'px" viewBox="0 0 '.$width.' 225">';
        foreach ([55, 110, 165] as $y) {
            $svg .= '<line x1="15" y1="'.$y.'" x2="'.($width - 15).'" y2="'.$y.'" stroke="#dce2db" stroke-dasharray="4 5"/>';
        }
        $step = ($width - 40) / $items->count();
        foreach ($items as $i => $row) {
            $height = 130 * (float) $row->$value / $max;
            $barWidth = min(64, $step * .48);
            $x = 20 + $i * $step + ($step - $barWidth) / 2;
            $center = $x + $barWidth / 2;
            $text = htmlspecialchars(substr((string) $row->$label, 0, 10), ENT_QUOTES, 'UTF-8');
            $score = round((float) $row->$value, 2);
            $svg .= '<rect x="'.$x.'" y="'.(175 - $height).'" width="'.$barWidth.'" height="'.$height.'" rx="6"><title>'.$text.': '.$score.'</title></rect><text x="'.$center.'" y="'.(163 - $height).'" text-anchor="middle" font-size="17">'.$score.'</text><text x="'.$center.'" y="205" text-anchor="middle" font-size="15">'.$text.'</text>';
        }

        return $svg.'</svg>';
    }
}
