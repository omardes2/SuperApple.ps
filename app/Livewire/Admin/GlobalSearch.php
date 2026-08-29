<?php

namespace App\Livewire\Admin;

use App\Services\GlobalSearchService;
use Livewire\Component;

/**
 * Top-bar global search. Results are produced by GlobalSearchService, which
 * gates every category by the viewer's permissions — this component never
 * bypasses that. Queries run server-side, limited per category.
 */
class GlobalSearch extends Component
{
    public string $q = '';

    public function clear(): void
    {
        $this->q = '';
    }

    public function render(GlobalSearchService $search)
    {
        $groups = trim($this->q) !== '' ? $search->search(auth()->user(), $this->q) : [];

        return view('livewire.admin.global-search', ['groups' => $groups]);
    }
}
