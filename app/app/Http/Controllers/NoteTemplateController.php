<?php

namespace App\Http\Controllers;

use App\Models\FindingNoteTemplate;
use Illuminate\Http\Request;

/**
 * Beheer van de uitleg-bibliotheek: de zinnen die automatisch worden
 * voorgesteld bij een interactie, criterium of product dat terugkomt.
 */
class NoteTemplateController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $templates = FindingNoteTemplate::query()
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('label', 'like', "%{$q}%")->orWhere('note_md', 'like', "%{$q}%");
            }))
            ->orderByDesc('last_used_at')
            ->paginate(50)
            ->withQueryString();

        return view('note-templates.index', ['templates' => $templates, 'q' => $q]);
    }

    public function update(Request $request, FindingNoteTemplate $noteTemplate)
    {
        $noteTemplate->update($request->validate([
            'note_md' => 'required|string',
        ]));

        // Bestaande reviews veranderen niet mee — dit geldt vanaf de volgende keer.
        return back()->with('status', 'Uitleg bijgewerkt. Geldt vanaf de volgende review.');
    }

    public function destroy(FindingNoteTemplate $noteTemplate)
    {
        $noteTemplate->delete();

        return back()->with('status', 'Uitleg verwijderd uit de bibliotheek.');
    }
}
