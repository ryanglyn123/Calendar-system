<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Settings\GeneralSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Illuminate\Support\Str;

/**
 * The printable booking form: an approved booking on paper, with a row per
 * named person to sign on the day. Always built from the row as it is now,
 * never from what a page happened to be showing.
 */
class BookingDocument
{
    public function __construct(private readonly GeneralSettings $settings) {}

    /** The PDF's bytes. */
    public function render(Booking $booking): string
    {
        $dompdf = Pdf::loadHTML($this->html($booking))
            ->setPaper('a4')
            ->setOption([
                'defaultFont' => 'Poppins',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
            ])
            ->getDomPDF();

        $dompdf->render();

        // CSS can't count pages in dompdf, so "Page X of Y" is drawn onto
        // every page once layout is done, right-aligned in the footer.
        $dompdf->getCanvas()->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $metrics) {
            $mm = 72 / 25.4;
            $font = $metrics->getFont('Poppins');
            $text = "Page {$page} of {$pages}";
            $size = 7.5;

            $canvas->text(
                $canvas->get_width() - 16 * $mm - $metrics->getTextWidth($text, $font, $size),
                $canvas->get_height() - 24 * $mm + 5.1 * $mm,
                $text,
                $font,
                $size,
                [0.42, 0.42, 0.39],
            );
        });

        return (string) $dompdf->output();
    }

    /** The form as HTML, before it becomes a PDF; tests read this. */
    public function html(Booking $booking): string
    {
        return view('pdf.booking-form', $this->data($booking))->render();
    }

    public function filename(Booking $booking): string
    {
        return $booking->reference().'-'.Str::slug($booking->resource->type->name ?? 'booking').'-form.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Booking $booking): array
    {
        $booking->loadMissing([
            'resource.type',
            'requester.division',
            'reviewer',
            'event:id,title',
        ]);

        $tz = $this->settings->timezone;
        $start = $booking->starts_at->copy()->timezone($tz);
        $end = $booking->ends_at->copy()->timezone($tz);
        $type = $booking->resource->type;
        $isVehicle = (bool) preg_match('/car|vehicle|van|shuttle|pool|transport/i', ($type->icon ?? '').' '.$type->name);
        $people = $booking->people ?? [];

        return [
            'org' => [
                'name' => $this->settings->organization_name,
                'acronym' => $this->settings->organization_acronym,
            ],
            'logo' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('img/Logoblk.png'))),
            'fonts' => str_replace('\\', '/', resource_path('fonts/poppins')),
            'reference' => $booking->reference(),
            'typeName' => $type->name,
            'nouns' => $isVehicle
                ? ['resource' => 'Vehicle', 'people' => 'Passengers', 'person' => 'passenger', 'seats' => 'seats']
                : ['resource' => 'Room', 'people' => 'Attendees', 'person' => 'attendee', 'seats' => 'seats'],
            'title' => $booking->event->title ?? $booking->purpose ?? 'Booking',
            'purpose' => $booking->purpose,
            'resource' => [
                'name' => $booking->resource->name,
                'location' => $booking->resource->location,
                'capacity' => $booking->resource->capacity,
            ],
            'when' => $this->when($start, $end),
            'summary' => $start->isSameDay($end)
                ? $start->format('l, F j, Y').' · '.$start->format('H:i').' – '.$end->format('H:i')
                : $start->format('M j, H:i').' – '.$end->format('M j, Y, H:i'),
            'requester' => [
                'name' => $booking->requester?->name ?? 'Former user',
                'division' => $booking->requester?->division?->name,
                'email' => $booking->requester?->email,
            ],
            'requestedAt' => $booking->created_at?->copy()->timezone($tz)->format('M j, Y, H:i'),
            'approvedBy' => $booking->reviewer?->name,
            'approvedAt' => $booking->reviewed_at?->copy()->timezone($tz)->format('M j, Y, H:i'),
            // Older bookings kept only a head count: give them blank rows
            // to fill in by hand rather than no rows at all.
            'people' => $people !== [] ? $people : array_fill(0, max(1, (int) $booking->attendee_count), ''),
            'namesRecorded' => $people !== [],
            'generatedAt' => now()->timezone($tz)->format('M j, Y, H:i'),
            'checkUrl' => route('bookings.show', $booking),
        ];
    }

    /**
     * Rows for the "when" block: a date and a time for a booking within one
     * day; a start and an end for one that runs across days (a trip).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function when(CarbonInterface $start, CarbonInterface $end): array
    {
        $length = $this->length($start, $end);

        if ($start->isSameDay($end) || $end->eq($start->copy()->addDay()->startOfDay())) {
            return [
                ['Date', $start->format('l, F j, Y')],
                ['Time', "{$start->format('H:i')} – {$end->format('H:i')} ({$length})"],
            ];
        }

        return [
            ['From', $start->format('l, F j, Y').' · '.$start->format('H:i')],
            ['Until', $end->format('l, F j, Y').' · '.$end->format('H:i')." ({$length})"],
        ];
    }

    private function length(CarbonInterface $start, CarbonInterface $end): string
    {
        $minutes = (int) $start->diffInMinutes($end);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $rest = $minutes % 60;

        return collect([
            $days ? $days.' '.Str::plural('day', $days) : null,
            $hours ? $hours.' '.Str::plural('hour', $hours) : null,
            $rest ? "{$rest} min" : null,
        ])->filter()->join(' ');
    }
}
