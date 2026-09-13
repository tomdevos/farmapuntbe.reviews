<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\Resident;
use App\Services\Export\ResidentGrouping;
use PHPUnit\Framework\TestCase;

class ResidentGroupingTest extends TestCase
{
    public function test_alphabetical_keeps_the_departments_and_sorts_on_name(): void
    {
        $groups = (new ResidentGrouping())->groups($this->residents(), ResidentGrouping::SORT_ALPHABETICAL);

        $this->assertSame(['Afdeling Marga', 'Afdeling Zonnebloem'], $groups->pluck('label')->all());
        $this->assertSame(
            ['De Groef Joanna', 'Peeters Maria', 'Van Damme Jos'],
            $groups->firstWhere('label', 'Afdeling Marga')['residents']->pluck('display_name')->all(),
        );
        $this->assertFalse($groups->first()['show_department']);
    }

    public function test_per_doctor_groups_across_departments_and_puts_the_unknown_last(): void
    {
        $groups = (new ResidentGrouping())->groups($this->residents(), ResidentGrouping::SORT_DOCTOR);

        $this->assertSame(
            ['Dr. Aerts', 'Dr. Janssens', ResidentGrouping::UNKNOWN_DOCTOR],
            $groups->pluck('label')->all(),
        );

        // Dr. Aerts ziet zijn bewoners uit beide afdelingen in één blok.
        $aerts = $groups->firstWhere('label', 'Dr. Aerts')['residents'];
        $this->assertSame(['De Groef Joanna', 'Willems Frans'], $aerts->pluck('display_name')->all());
        $this->assertSame(['Marga', 'Zonnebloem'], $aerts->pluck('department.name')->all());

        // De afdeling vervangt de arts in de kopregel van elke bewoner.
        $this->assertTrue($groups->first()['show_department']);
    }

    private function residents(): \Illuminate\Support\Collection
    {
        $marga = new Department(['name' => 'Marga']);
        $marga->id = 1;
        $zonnebloem = new Department(['name' => 'Zonnebloem']);
        $zonnebloem->id = 2;

        return collect([
            $this->resident('Maria', 'Peeters', 'Dr. Janssens', $marga),
            $this->resident('Frans', 'Willems', 'Dr. Aerts', $zonnebloem),
            $this->resident('Jos', 'Van Damme', '', $marga),
            $this->resident('Joanna', 'De Groef', 'Dr. Aerts', $marga),
        ]);
    }

    private function resident(string $first, string $last, string $doctor, Department $dept): Resident
    {
        $r = new Resident([
            'first_name' => $first, 'last_name' => $last, 'doctor_name' => $doctor,
        ]);
        $r->department_id = $dept->id;
        $r->setRelation('department', $dept);

        return $r;
    }
}
