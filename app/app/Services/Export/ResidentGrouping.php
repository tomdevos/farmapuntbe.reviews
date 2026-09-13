<?php

namespace App\Services\Export;

use App\Models\Resident;
use Illuminate\Support\Collection;

/**
 * Decides in welke blokken de bewoners in het document terechtkomen:
 * alfabetisch binnen hun afdeling, of samen onder hun behandelend arts.
 */
class ResidentGrouping
{
    public const SORT_ALPHABETICAL = 'alfabetisch';
    public const SORT_DOCTOR = 'arts';

    public const SORTS = [self::SORT_ALPHABETICAL, self::SORT_DOCTOR];

    public const UNKNOWN_DOCTOR = 'Arts niet gekend';

    /**
     * @param  Collection<int, Resident>  $residents
     * @return Collection<int, array{label: string, residents: Collection<int, Resident>, show_department: bool}>
     */
    public function groups(Collection $residents, string $sort): Collection
    {
        $residents = $residents->filter();

        return $sort === self::SORT_DOCTOR
            ? $this->byDoctor($residents)
            : $this->byDepartment($residents);
    }

    private function byDepartment(Collection $residents): Collection
    {
        return $residents
            ->groupBy(fn (Resident $r) => $r->department_id)
            ->sortBy(fn (Collection $group) => $this->sortKey($group->first()->department?->name ?? ''))
            ->map(fn (Collection $group) => [
                'label' => 'Afdeling ' . ($group->first()->department?->name ?? '—'),
                'residents' => $this->alphabetically($group),
                'show_department' => false,
            ])
            ->values();
    }

    private function byDoctor(Collection $residents): Collection
    {
        return $residents
            ->groupBy(fn (Resident $r) => $this->doctorLabel($r))
            ->sortBy(function (Collection $group, string $label) {
                // Bewoners zonder gekende arts komen achteraan.
                return ($label === self::UNKNOWN_DOCTOR ? '1' : '0') . $this->sortKey($label);
            })
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'residents' => $this->alphabetically($group),
                'show_department' => true,
            ])
            ->values();
    }

    private function doctorLabel(Resident $resident): string
    {
        $name = trim((string) $resident->doctor_name);

        return $name !== '' ? $name : self::UNKNOWN_DOCTOR;
    }

    private function alphabetically(Collection $residents): Collection
    {
        return $residents
            ->sortBy(fn (Resident $r) => $this->sortKey($r->last_name . ' ' . $r->first_name))
            ->values();
    }

    /** Accentloos en hoofdletterongevoelig sorteren, zoals de apotheker het verwacht. */
    private function sortKey(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return mb_strtolower($ascii === false ? $value : $ascii);
    }
}
