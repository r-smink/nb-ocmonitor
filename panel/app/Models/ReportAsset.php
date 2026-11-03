<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Een geüpload bestand voor de rapporten (base64 in de database). */
class ReportAsset extends Model
{
    public const KINDS = [
        'logo' => 'Logo',
        'background_cover' => 'Achtergrond voorpagina',
        'background_page' => 'Achtergrond overige pagina\'s',
        'font_regular' => 'Eigen font (normaal)',
        'font_bold' => 'Eigen font (vet)',
    ];

    protected $fillable = ['kind', 'filename', 'mime', 'size', 'data'];

    protected $hidden = ['data'];

    public function bytes(): string
    {
        return (string) base64_decode($this->data, true);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    /** @return array<string, self> */
    public static function byKind(): array
    {
        return self::query()->get(['id', 'kind', 'filename', 'mime', 'size', 'updated_at'])->keyBy('kind')->all();
    }
}
