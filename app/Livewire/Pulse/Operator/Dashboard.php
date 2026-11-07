<?php

namespace App\Livewire\Pulse\Operator;

use App\Services\Pulse\Operator\Metrics;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The operator's numbers (platform moderators only). Everything on the page comes from Metrics, which counts real
 * rows over the range shown at the top. A metric with nothing behind it says "No data yet".
 */
class Dashboard extends Component
{
    public const RANGES = [7, 30, 90];

    public int $days = 30;

    public function mount(): void
    {
        $this->authorizeOperator();
    }

    public function setRange(int $days): void
    {
        $this->authorizeOperator();

        if (in_array($days, self::RANGES, true)) {
            $this->days = $days;
            unset($this->snapshot);
        }
    }

    private function authorizeOperator(): void
    {
        abort_unless(auth()->user()?->isPlatformModerator(), 403);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function snapshot(): array
    {
        $this->authorizeOperator();

        return app(Metrics::class)->snapshot($this->days);
    }

    public function render(): View
    {
        $this->authorizeOperator();

        return view('livewire.pulse.operator.dashboard', ['m' => $this->snapshot]);
    }

    // -- formatting, shared with the view ----------------------------------------------------------------------

    public static function count(?int $n): ?string
    {
        return $n === null ? null : number_format($n);
    }

    public static function percent(?float $rate): ?string
    {
        return $rate === null ? null : number_format($rate * 100, 0).'%';
    }

    /** Whole seconds in the largest two units that read well: "3 h 20 min", "2 d 4 h", "45 s". */
    public static function duration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds.' s';
        }

        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $min = intdiv($seconds % 3600, 60);

        return match (true) {
            $d > 0 => $d.' d'.($h ? " {$h} h" : ''),
            $h > 0 => $h.' h'.($min ? " {$min} min" : ''),
            default => $min.' min',
        };
    }
}
