<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CommentsExport implements FromArray, WithHeadings
{
    private array $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function array(): array
    {
        // Expect: [['text' => '...', 'sentiment' => 'positive'], ...]
        return array_map(function ($r) {
            return [
                $r['text'] ?? '',
                $r['sentiment'] ?? 'neutral',
            ];
        }, $this->rows);
    }

    public function headings(): array
    {
        return ['Komentar', 'Sentimen'];
    }
}
