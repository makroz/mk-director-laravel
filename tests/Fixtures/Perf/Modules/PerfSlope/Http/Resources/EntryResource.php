<?php

declare(strict_types=1);

namespace App\Modules\PerfSlope\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->owner?->name, // capture: owner
        ];
    }
}
