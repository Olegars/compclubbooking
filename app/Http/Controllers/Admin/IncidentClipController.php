<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IncidentClipJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IncidentClipController extends Controller
{
    public function stream(Request $request, int $job): BinaryFileResponse
    {
        $row = IncidentClipJob::query()->find($job);
        if (! $row || $row->status !== IncidentClipJob::STATUS_SENT || ! $row->file_path) {
            abort(404);
        }
        if (! Storage::disk('local')->exists($row->file_path)) {
            abort(404);
        }

        $absolute = Storage::disk('local')->path($row->file_path);
        $name = $row->file_name ?: 'incident.mp4';
        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($request->boolean('download')) {
            return response()->download($absolute, $name, $headers);
        }

        return response()->file($absolute, $headers);
    }
}
