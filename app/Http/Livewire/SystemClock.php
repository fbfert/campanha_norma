<?php

namespace App\Http\Livewire;

use Livewire\Component;

class SystemClock extends Component
{
    public function render()
    {
        return view('livewire.system-clock', [
            'now' => now()->format('d/m/Y H:i'),
        ]);
    }
}
